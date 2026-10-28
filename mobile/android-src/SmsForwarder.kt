package com.klokads.finance

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID
import java.util.concurrent.TimeUnit

/**
 * Payment SMS forwarding, done natively so it works with the app closed.
 *
 * Only SMS from the senders the server named (bKash, NAGAD, 16216, the bank) are
 * touched; everyone else's never leave the phone, and neither does one with an OTP,
 * PIN or code in it. Each one is first written to a queue on the phone, then sent by
 * WorkManager whenever there is internet, again and again until the server says it
 * has it, so a payment SMS is never lost — not without internet, not after a restart.
 * The server books each TrxID once, so a resend never books it twice.
 *
 * A sender name alone can be faked on some SMS routes, so each SMS also carries the
 * service centre (SMSC) that delivered it: the server can tell a payment SMS that came
 * by an unusual route and leave it for the Owner instead of settling it by itself.
 *
 * The phone also keeps a short log of what it sent and what the server made of it
 * (matched an invoice, kept, not money), shown in the app with a retry; and once an
 * hour it tells the server it is alive, so a phone that stopped forwarding is noticed.
 */
object SmsForwarder {
    /** Flutter's shared_preferences file, where the app keeps the hook URL and senders. */
    private const val FLUTTER = "FlutterSharedPreferences"
    private const val QUEUE = "klok_sms_queue"
    private const val LOG = "klok_sms_log"
    private const val WORK = "sms-forward"
    private const val BEAT = "sms-heartbeat"
    private const val LOG_SIZE = 150

    /** Never sent, whatever the sender: the words of one-time codes. */
    val DEFAULT_IGNORE = listOf(
        "otp", "one time password", "verification code", "verification number", "security code",
        "access code", "auth code", "login code", "confirmation code", "your code is", "use code", "pin",
    )

    /**
     * A card notification (NSave) that carries a one-time code: its words, or a 4-8 digit number
     * right next to "code" / "OTP" (either side). Such a notification is never sent.
     */
    private val CARD_OTP = Regex(
        "\\b(otp|one[-\\s]?time|verification\\s+code|passcode|security\\s+code|3ds|code\\s+is)\\b" +
            "|\\b(code|otp)\\W{0,3}\\d{4,8}\\b" +
            "|\\b\\d{4,8}\\W{0,3}(code|otp)\\b" +
            // "834201 is your (NSave) code"
            "|\\b\\d{4,8}\\s+is\\s+your\\s+(\\w+\\s+)?(code|otp)\\b",
        RegexOption.IGNORE_CASE,
    )

    private fun flutter(context: Context) = context.getSharedPreferences(FLUTTER, Context.MODE_PRIVATE)

    private fun url(context: Context): String? = flutter(context).getString("flutter.sms.url", null)

    /** This phone is the SMS forwarder (the hook URL is set): the same rule for NSave's notifications. */
    fun forwarding(context: Context): Boolean = url(context) != null

    /** The senders the server named, less those switched off on this phone. */
    private fun senders(context: Context): Set<String> {
        val off = (flutter(context).getString("flutter.sms.disabled", "") ?: "")
            .split(',').map { normalise(it) }.filter { it.isNotEmpty() }.toSet()
        return (flutter(context).getString("flutter.sms.senders", "") ?: "")
            .split(',').map { normalise(it) }.filter { it.isNotEmpty() && it !in off }.toSet()
    }

    fun ignoreWords(context: Context): List<String> {
        val saved = flutter(context).getString("flutter.sms.ignore", null) ?: return DEFAULT_IGNORE
        return saved.split('\n').map { it.trim().lowercase() }.filter { it.isNotEmpty() }
    }

    fun normalise(sender: String) = sender.lowercase().replace(Regex("\\s+"), "")

    /** A word on its own (so "pin" is not found inside "shopping"). */
    private fun hasWord(text: String, word: String): Boolean =
        Regex("(^|[^\\p{L}\\p{N}])" + Regex.escape(word) + "($|[^\\p{L}\\p{N}])").containsMatchIn(text)

    @Synchronized
    private fun read(context: Context, name: String = QUEUE): JSONArray =
        try {
            JSONArray(context.getSharedPreferences(name, Context.MODE_PRIVATE).getString("items", "[]"))
        } catch (e: Exception) {
            JSONArray()
        }

    @Synchronized
    private fun write(context: Context, items: JSONArray, name: String = QUEUE) {
        context.getSharedPreferences(name, Context.MODE_PRIVATE).edit().putString("items", items.toString()).commit()
    }

    fun pending(context: Context): Int = read(context).length()

    /** The log, newest first, as JSON for the app. */
    fun log(context: Context): String = read(context, LOG).toString()

    fun clearLog(context: Context) = write(context, JSONArray(), LOG)

    /** Keeps only the log lines of the last [days] days. */
    @Synchronized
    fun cleanLog(context: Context, days: Int): Int {
        val since = System.currentTimeMillis() - days * 86_400_000L
        val items = read(context, LOG)
        val kept = JSONArray()
        for (i in 0 until items.length()) {
            if (items.getJSONObject(i).optLong("at") >= since) kept.put(items.getJSONObject(i))
        }
        write(context, kept, LOG)
        return items.length() - kept.length()
    }

    /**
     * Payment SMS already in the phone's inbox from the last [days] days — ones that came while
     * forwarding was off or another app was in use — sent now. One the log already has is not
     * sent again; the server books each TrxID once anyway.
     */
    fun importInbox(context: Context, days: Int): Int {
        if (url(context) == null) return 0
        val since = System.currentTimeMillis() - days * 86_400_000L
        val known = HashSet<String>()
        val log = read(context, LOG)
        for (i in 0 until log.length()) known.add(log.getJSONObject(i).optString("text"))
        var queued = 0
        val cursor = context.contentResolver.query(
            android.net.Uri.parse("content://sms/inbox"),
            arrayOf("address", "body", "date", "service_center"),
            "date >= ?", arrayOf(since.toString()), "date ASC",
        ) ?: return 0
        try {
            while (cursor.moveToNext()) {
                val from = cursor.getString(0)
                val text = cursor.getString(1)
                if (from == null || text == null || text in known) {
                    continue
                }
                if (enqueue(context, from, text, cursor.getLong(2), cursor.getString(3))) {
                    known.add(text)
                    queued++
                }
            }
        } finally {
            cursor.close()
        }
        if (queued > 0) schedule(context)
        return queued
    }

    /** Adds a log line at the top, or updates the one with this id where it is; the oldest fall off. */
    @Synchronized
    private fun note(context: Context, id: String, fill: (JSONObject) -> Unit) {
        val items = read(context, LOG)
        for (i in 0 until items.length()) {
            if (items.getJSONObject(i).optString("id") == id) {
                fill(items.getJSONObject(i))
                write(context, items, LOG)
                return
            }
        }
        val line = JSONObject().put("id", id)
        fill(line)
        val out = JSONArray().put(line)
        for (i in 0 until minOf(items.length(), LOG_SIZE - 1)) {
            out.put(items.getJSONObject(i))
        }
        write(context, out, LOG)
    }

    /**
     * @param card a card provider's app notification (NSave), not an SMS: it is not held to the
     *             SMS senders list or the ignore words; the server reads it as a card move.
     * @param app  the package of the app that posted it (card only); sent as "package".
     */
    @Synchronized
    fun enqueue(context: Context, from: String, text: String, at: Long, centre: String? = null, card: Boolean = false, app: String? = null): Boolean {
        if (url(context) == null || (!card && !senders(context).contains(normalise(from)))) {
            return false
        }
        val id = UUID.randomUUID().toString()
        if (card && CARD_OTP.containsMatchIn(text)) {
            // A card's one-time code (a 3DS check at a shop) never leaves the phone: logged, no words.
            note(context, id) { it.put("from", from).put("text", "").put("at", at).put("status", "skipped").put("why", "OTP") }
            return false
        }
        if (!card) {
            val lower = text.lowercase()
            // Money coming in always goes, even when the bank adds "never share your PIN/OTP" to it.
            val money = Regex("\\b(received|credited|deposited|cash\\s*in)\\b").containsMatchIn(lower)
            val word = if (money) null else ignoreWords(context).firstOrNull { hasWord(lower, it) }
            if (word != null) {
                // Kept off the server, and off the log too: only that one was held back, and why.
                note(context, id) { it.put("from", from).put("text", "").put("at", at).put("status", "skipped").put("why", word) }
                return false
            }
        }
        val items = read(context)
        val item = JSONObject().put("id", id).put("from", from).put("text", text).put("at", at).put("smsc", centre ?: "")
        if (app != null) item.put("package", app)
        items.put(item)
        write(context, items)
        note(context, id) {
            it.put("from", from).put("text", text).put("at", at).put("smsc", centre ?: "").put("status", "pending")
            if (app != null) it.put("package", app)
        }
        return true
    }

    /** Sends one log line again (a failed or stuck one). */
    @Synchronized
    fun retry(context: Context, id: String): Boolean {
        val items = read(context, LOG)
        for (i in 0 until items.length()) {
            val line = items.getJSONObject(i)
            if (line.optString("id") == id && line.optString("text").isNotEmpty()) {
                val queue = read(context)
                var queued = false
                for (j in 0 until queue.length()) {
                    if (queue.getJSONObject(j).optString("id") == id) queued = true
                }
                if (!queued) {
                    val item = JSONObject().put("id", id).put("from", line.optString("from")).put("text", line.optString("text")).put("at", line.optLong("at")).put("smsc", line.optString("smsc"))
                    if (line.has("package")) item.put("package", line.optString("package"))
                    queue.put(item)
                    write(context, queue)
                }
                note(context, id) { it.put("status", "pending").remove("error") }
                schedule(context)
                return true
            }
        }
        return false
    }

    @Synchronized
    private fun remove(context: Context, id: String) {
        val items = read(context)
        val kept = JSONArray()
        for (i in 0 until items.length()) {
            if (items.getJSONObject(i).getString("id") != id) {
                kept.put(items.getJSONObject(i))
            }
        }
        write(context, kept)
    }

    /** Sends the queue as soon as there is internet; retried with growing gaps until done. */
    fun schedule(context: Context) {
        val request = OneTimeWorkRequestBuilder<SmsWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(WORK, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
    }

    /** Once an hour, with internet: "this phone still forwards". */
    fun keepAlive(context: Context) {
        val request = PeriodicWorkRequestBuilder<SmsHeartbeat>(1, TimeUnit.HOURS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(BEAT, ExistingPeriodicWorkPolicy.KEEP, request)
    }

    /** An empty SMS is the heartbeat; the server notes the time and nothing else. */
    fun beat(context: Context): Boolean {
        val target = url(context) ?: return true
        val (status, _) = try {
            post(target, false, JSONObject().put("from", "heartbeat").put("text", ""))
        } catch (e: Exception) {
            return false
        }
        if (status == 404) {
            flutter(context).edit().putString("flutter.sms.error", "revoked").apply()
            return true
        }
        if (status in 200..299) {
            flutter(context).edit().putLong("flutter.sms.last_beat", System.currentTimeMillis()).apply()
        }
        return status in 200..299
    }

    /**
     * @param quick the try made the moment an SMS arrives: short timeouts, to finish inside the
     *              few seconds Android gives a receiver.
     * @return true when the queue is empty afterwards.
     */
    fun flush(context: Context, quick: Boolean = false): Boolean {
        val target = url(context) ?: return true
        val items = read(context)
        for (i in 0 until items.length()) {
            val item = items.getJSONObject(i)
            val id = item.getString("id")
            val (status, body) = try {
                val payload = JSONObject()
                    .put("from", item.getString("from"))
                    .put("text", item.getString("text"))
                    .put("receivedStamp", item.getLong("at"))
                    .put("serviceCentre", item.optString("smsc"))
                // Only an NSave notification carries the app it came from; an SMS goes as before.
                if (item.has("package")) payload.put("package", item.optString("package"))
                post(target, quick, payload)
            } catch (e: Exception) {
                note(context, id) { it.put("error", "নেট নেই — আবার চেষ্টা হবে") }
                return false
            }
            when {
                status in 200..299 -> {
                    remove(context, id)
                    flutter(context).edit().putLong("flutter.sms.last_ok", System.currentTimeMillis()).apply()
                    val answer = try { JSONObject(body) } catch (e: Exception) { JSONObject() }
                    note(context, id) {
                        it.put("status", "synced").put("server", answer.optString("status")).put("sent_at", System.currentTimeMillis()).remove("error")
                        if (answer.has("invoice")) it.put("invoice", answer.optString("invoice"))
                    }
                }
                // The forwarding was moved to another phone or turned off on the server.
                status == 404 -> {
                    flutter(context).edit().putString("flutter.sms.error", "revoked").apply()
                    note(context, id) { it.put("status", "failed").put("error", "এই phone-এর forward বন্ধ — app-এ আবার চালু করুন") }
                    return false
                }
                else -> {
                    note(context, id) { it.put("status", "failed").put("error", "Server উত্তর দিল $status — আবার চেষ্টা হবে") }
                    return false
                }
            }
        }
        return read(context).length() == 0
    }

    private fun post(target: String, quick: Boolean, body: JSONObject): Pair<Int, String> {
        val connection = URL(target).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = if (quick) 4000 else 15000
            connection.readTimeout = if (quick) 5000 else 20000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val code = connection.responseCode
            val text = try {
                (if (code < 400) connection.inputStream else connection.errorStream)?.bufferedReader()?.use { it.readText() } ?: ""
            } catch (e: Exception) {
                ""
            }
            return code to text
        } finally {
            connection.disconnect()
        }
    }
}

class SmsWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result =
        if (SmsForwarder.flush(applicationContext)) Result.success() else Result.retry()
}

class SmsHeartbeat(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result {
        // Anything still waiting goes first; then "alive".
        SmsForwarder.flush(applicationContext)
        return if (SmsForwarder.beat(applicationContext)) Result.success() else Result.retry()
    }
}

/** Android hands every incoming SMS here, even with the app closed. */
class SmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
            return
        }
        val parts = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return
        // A long SMS arrives in pieces: joined back per sender.
        val bySender = LinkedHashMap<String, StringBuilder>()
        // The service centre that delivered it, which a faked sender name does not change.
        val centres = HashMap<String, String?>()
        var at = System.currentTimeMillis()
        for (part in parts) {
            val from = part.originatingAddress ?: continue
            bySender.getOrPut(from) { StringBuilder() }.append(part.messageBody ?: "")
            if (centres[from] == null) centres[from] = part.serviceCenterAddress
            at = part.timestampMillis
        }
        var queued = false
        for ((from, text) in bySender) {
            queued = SmsForwarder.enqueue(context, from, text.toString(), at, centres[from]) || queued
        }
        if (!queued) {
            return
        }
        // Sent at once, while Android lets this receiver run (about ten seconds); whatever
        // could not go — no internet, server slow — waits for WorkManager, so nothing is lost.
        // WorkManager first, so even if Android stops us mid-way it still goes; the server books
        // each SMS once, so sending it twice does no harm.
        SmsForwarder.schedule(context)
        val pending = goAsync()
        Thread {
            try {
                SmsForwarder.flush(context, quick = true)
            } catch (_: Throwable) {
            } finally {
                pending.finish()
            }
        }.start()
    }
}
