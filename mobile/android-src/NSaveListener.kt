package com.klokads.finance

import android.app.Notification
import android.content.Context
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.util.Log
import org.json.JSONObject

/**
 * NSave, the agency's USD card, tells of every card move (a charge at a shop, a refund, money
 * added) in its app's notifications, not by SMS. Android hands every notification on the phone
 * here once the Owner gives KlokOne notification access; only NSave's are read, and they go to
 * the server the way a payment SMS does (SmsForwarder: queue, send, retry, log), from "NSave".
 *
 * Only on the phone that forwards payment SMS (the hook URL is set): the same rule as SMS.
 * Every other app's notifications are looked at for their sender only and never leave the phone.
 */
class NSaveListener : NotificationListenerService() {
    /** Whether a package is NSave, by its app name (a lookup per package, not per notification). */
    private val byLabel = HashMap<String, Boolean>()

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        val posted = sbn ?: return
        try {
            handle(posted)
        } catch (_: Throwable) {
            // A strange notification must never stop the listener (Android unbinds one that crashes).
        }
    }

    private fun handle(sbn: StatusBarNotification) {
        val context = applicationContext
        if (sbn.packageName == packageName || !SmsForwarder.forwarding(context)) {
            return
        }
        val app = sbn.packageName ?: return
        if (!isNSave(app)) {
            return
        }
        val notification = sbn.notification ?: return
        // An ongoing one (a sync, a download) and a group's summary line are not card moves.
        if (sbn.isOngoing || (notification.flags and Notification.FLAG_GROUP_SUMMARY) != 0) {
            return
        }
        val extras = notification.extras ?: return
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString()?.trim().orEmpty()
        val body = (
            extras.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString()?.trim()?.takeIf { it.isNotEmpty() }
                ?: extras.getCharSequence(Notification.EXTRA_TEXT)?.toString()?.trim()
            ).orEmpty()
        val text = listOf(title, body).filter { it.isNotEmpty() }.joinToString("\n")
        if (text.isEmpty()) {
            return
        }
        Log.i(TAG, "NSave notification from $app")
        // The package seen, for the app's settings page (and so the right one can be checked).
        context.getSharedPreferences("FlutterSharedPreferences", Context.MODE_PRIVATE).edit().putString("flutter.nsave.package", app).apply()
        // Android posts a notification again when it is updated: the same one, same words, once.
        if (seen(context, sbn.key ?: "$app:${sbn.id}", text)) {
            return
        }
        if (!SmsForwarder.enqueue(context, "NSave", text, sbn.postTime, null, card = true, app = app)) {
            return
        }
        // As with an SMS: WorkManager first (it goes even if Android stops us), then a quick try now.
        SmsForwarder.schedule(context)
        Thread {
            try {
                SmsForwarder.flush(context, quick = true)
            } catch (_: Throwable) {
            }
        }.start()
    }

    private fun isNSave(app: String): Boolean {
        if (app.contains("nsave", ignoreCase = true)) {
            return true
        }
        byLabel[app]?.let { return it }
        return try {
            val pm = packageManager
            @Suppress("DEPRECATION")
            val label = pm.getApplicationLabel(pm.getApplicationInfo(app, 0)).toString()
            label.contains("NSave").also { byLabel[app] = it }
        } catch (_: Exception) {
            // Not visible to us or gone: not NSave this time; looked up again next time.
            false
        }
    }

    companion object {
        private const val TAG = "NSaveListener"
        private const val SEEN = "klok_nsave_seen"
        private const val WINDOW = 10 * 60 * 1000L

        /**
         * True when this notification (its key) with these same words was already queued in the
         * last ten minutes; else notes it now. Kept on disk, so a restart of the listener does not
         * send an update twice.
         */
        @Synchronized
        fun seen(context: Context, key: String, text: String): Boolean {
            val prefs = context.getSharedPreferences(SEEN, Context.MODE_PRIVATE)
            val now = System.currentTimeMillis()
            val old = try { JSONObject(prefs.getString("items", "{}") ?: "{}") } catch (_: Exception) { JSONObject() }
            val kept = JSONObject()
            val names = old.keys()
            while (names.hasNext()) {
                val name = names.next()
                val at = old.optLong(name)
                if (now - at < WINDOW) kept.put(name, at)
            }
            val sign = key + "|" + text.hashCode() + "|" + text.length
            if (kept.has(sign)) {
                return true
            }
            kept.put(sign, now)
            prefs.edit().putString("items", kept.toString()).commit()
            return false
        }
    }
}
