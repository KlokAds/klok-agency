package com.klokads.finance

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
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
 * touched; everyone else's never leave the phone. Each one is first written to a
 * queue on the phone, then sent by WorkManager whenever there is internet, again
 * and again until the server says it has it, so a payment SMS is never lost —
 * not without internet, not after a restart. The server books each TrxID once,
 * so a resend never books it twice.
 */
object SmsForwarder {
    /** Flutter's shared_preferences file, where the app keeps the hook URL and senders. */
    private const val FLUTTER = "FlutterSharedPreferences"
    private const val QUEUE = "klok_sms_queue"
    private const val WORK = "sms-forward"

    private fun flutter(context: Context) = context.getSharedPreferences(FLUTTER, Context.MODE_PRIVATE)

    private fun url(context: Context): String? = flutter(context).getString("flutter.sms.url", null)

    private fun senders(context: Context): Set<String> =
        (flutter(context).getString("flutter.sms.senders", "") ?: "")
            .split(',').map { normalise(it) }.filter { it.isNotEmpty() }.toSet()

    fun normalise(sender: String) = sender.lowercase().replace(Regex("\\s+"), "")

    @Synchronized
    private fun read(context: Context): JSONArray =
        try {
            JSONArray(context.getSharedPreferences(QUEUE, Context.MODE_PRIVATE).getString("items", "[]"))
        } catch (e: Exception) {
            JSONArray()
        }

    @Synchronized
    private fun write(context: Context, items: JSONArray) {
        context.getSharedPreferences(QUEUE, Context.MODE_PRIVATE).edit().putString("items", items.toString()).commit()
    }

    fun pending(context: Context): Int = read(context).length()

    @Synchronized
    fun enqueue(context: Context, from: String, text: String, at: Long): Boolean {
        if (url(context) == null || !senders(context).contains(normalise(from))) {
            return false
        }
        val items = read(context)
        items.put(JSONObject().put("id", UUID.randomUUID().toString()).put("from", from).put("text", text).put("at", at))
        write(context, items)
        return true
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
            val status = try {
                post(target, quick, JSONObject()
                    .put("from", item.getString("from"))
                    .put("text", item.getString("text"))
                    .put("receivedStamp", item.getLong("at")))
            } catch (e: Exception) {
                return false
            }
            when {
                status in 200..299 -> {
                    remove(context, item.getString("id"))
                    flutter(context).edit().putLong("flutter.sms.last_ok", System.currentTimeMillis()).apply()
                }
                // The forwarding was moved to another phone or turned off on the server.
                status == 404 -> {
                    flutter(context).edit().putString("flutter.sms.error", "revoked").apply()
                    return false
                }
                else -> return false
            }
        }
        return read(context).length() == 0
    }

    private fun post(target: String, quick: Boolean, body: JSONObject): Int {
        val connection = URL(target).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = if (quick) 4000 else 15000
            connection.readTimeout = if (quick) 5000 else 20000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            return connection.responseCode
        } finally {
            connection.disconnect()
        }
    }
}

class SmsWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result =
        if (SmsForwarder.flush(applicationContext)) Result.success() else Result.retry()
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
        var at = System.currentTimeMillis()
        for (part in parts) {
            val from = part.originatingAddress ?: continue
            bySender.getOrPut(from) { StringBuilder() }.append(part.messageBody ?: "")
            at = part.timestampMillis
        }
        var queued = false
        for ((from, text) in bySender) {
            queued = SmsForwarder.enqueue(context, from, text.toString(), at) || queued
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
