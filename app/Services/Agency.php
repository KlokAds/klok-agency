<?php

namespace App\Services;

use App\Models\AdRun;
use App\Models\Task;
use App\Notifications\AgencyAlert;
use App\Support\ClientMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The reminders an ad run brings with it, and what happens when its result
 * is noted or it is renewed. Task titles for these are stored in বাংলা source
 * form (translated when shown) and always read with the client's name.
 */
class Agency
{
    public const RENEW_TITLE = 'Ad শেষ হচ্ছে — client-কে renew-এর SMS দিন'; // i18n

    public const RESULT_TITLE = 'Ad-এর ফলাফল লিখুন (ভালো / খারাপ)'; // i18n

    public const SCALE_TITLE = 'ভালো চলছে — client-কে budget বাড়ানোর কথা বলুন'; // i18n

    public const FIX_TITLE = 'ফলাফল খারাপ — creative / audience বদলান বা বন্ধের কথা বলুন'; // i18n

    public const ENDED_TITLE = 'Ad শেষ হয়ে গেছে, renew হয়নি'; // i18n

    public function __construct(private Notifier $notifier) {}

    /** Creates (or re-times) the renew and result reminders of a run. */
    public function plan(AdRun $run): void
    {
        // Untouched automatic reminders are re-made; anything already sent or done stays.
        $run->tasks()->whereIn('kind', ['renewal', 'result'])->whereNull('done_at')->whereNull('notified_at')->delete();

        if ($run->status !== 'running') {
            return;
        }

        $renewAt = $this->at($run->end_on->subDays($run->remind_days_before), 10);
        $this->task($run, 'renewal', self::RENEW_TITLE, max($renewAt, now()->addMinute()), $this->at($run->end_on, 18));

        if (! $run->result) {
            $checkAt = $this->at($run->start_on->addDays(min(2, max(0, $run->days - 1))), 18);
            $this->task($run, 'result', self::RESULT_TITLE, max($checkAt, now()->addMinute()), $this->at($run->end_on, 18));
        }
    }

    /**
     * Notes how the ad did, and asks for the next step when it is clearly good or bad.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordResult(AdRun $run, array $data): void
    {
        DB::transaction(function () use ($run, $data) {
            $run->update($data + ['result_at' => now()]);
            $run->tasks()->where('kind', 'result')->whereNull('done_at')->update(['done_at' => now(), 'done_by' => auth()->id()]);
            $run->tasks()->where('kind', 'scale')->whereNull('done_at')->whereNull('notified_at')->delete();

            match ($run->result) {
                'good' => $this->task($run, 'scale', self::SCALE_TITLE, $this->at(now()->addDay(), 11), $this->at(now()->addDays(2), 18)),
                'bad' => $this->task($run, 'scale', self::FIX_TITLE, $this->at(now()->addDay(), 10), $this->at(now()->addDay(), 18), 'high'),
                default => null,
            };
        });
    }

    /**
     * A new run that continues an old one.
     *
     * @param  array<string, mixed>  $data
     */
    public function renew(AdRun $old, array $data): AdRun
    {
        return DB::transaction(function () use ($old, $data) {
            $run = AdRun::create($data + [
                'client_id' => $old->client_id,
                'account_id' => $old->account_id,
                'assigned_to' => $old->assigned_to,
                'renewed_from_id' => $old->id,
                'remind_days_before' => $old->remind_days_before,
            ]);

            $old->update(['status' => 'renewed']);
            $old->tasks()->whereIn('kind', ['renewal'])->whereNull('done_at')->update(['done_at' => now(), 'done_by' => auth()->id()]);
            $this->plan($run);

            return $run;
        });
    }

    /** Runs past their end date are marked ended; un-renewed ones are reported once. */
    public function closeEnded(): int
    {
        $ended = AdRun::with(['client', 'account'])->where('status', 'running')->where('end_on', '<', now()->startOfDay())->get();

        foreach ($ended as $run) {
            $run->update(['status' => 'ended']);
            $run->tasks()->where('kind', 'result')->whereNull('done_at')->whereNull('notified_at')->delete();

            $this->notifier->send($run->responsibleId(), new AgencyAlert('ended', self::ENDED_TITLE, '{client} · {title}', [
                'client' => $run->client?->name,
                'title' => $run->title,
            ], ['url' => '/runs?focus='.$run->id] + $this->contact($run)));
        }

        return $ended->count();
    }

    /**
     * WhatsApp / SMS links with the renewal text, when the client has a phone number.
     *
     * @return array{whatsapp: ?string, sms: ?string}
     */
    public function contact(AdRun $run): array
    {
        $links = ClientMessage::links($run->client?->phone, ClientMessage::renewal($run));

        return ['whatsapp' => $links['whatsapp'], 'sms' => $links['sms']];
    }

    private function task(AdRun $run, string $kind, string $title, CarbonInterface $remindAt, CarbonInterface $dueAt, string $priority = 'normal'): Task
    {
        return $run->tasks()->create([
            'title' => $title,
            'kind' => $kind,
            'priority' => $priority,
            'client_id' => $run->client_id,
            'assigned_to' => $run->responsibleId(),
            'remind_at' => $remindAt,
            'due_at' => $dueAt,
        ]);
    }

    private function at(CarbonInterface $day, int $hour): CarbonInterface
    {
        return $day->setTime($hour, 0);
    }
}
