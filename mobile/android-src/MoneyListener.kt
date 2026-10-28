package com.klokads.finance

import android.app.Notification
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject

/**
 * Card and bank apps (NSave, Fasset, PlasmaOne, Elevate Pay, …) tell of every dollar move (a
 * charge at a shop, a refund, money added) in their notifications, not by SMS. Android hands every
 * notification on the phone here once the Owner gives KlokOne notification access. One that names
 * a US-dollar amount and a money word goes to the server the way a payment SMS does (SmsForwarder:
 * queue, send, retry, log), from "app", with the app's package and name; the server decides what
 * a new app's notifications are worth (the Owner approves each app on the web).
 *
 * Never read: this app, SMS / chat / email apps, browsers and the phone's own system. A one-time
 * code never leaves the phone (SmsForwarder's OTP check). Each app can be switched off here, and
 * all of it at once (MoneyApps). Only on the phone that forwards payment SMS: the same rule as SMS.
 */
class MoneyListener : NotificationListenerService() {
    /** Each app's name, looked up once per package. */
    private val labels = HashMap<String, String>()
    private var browsers: Set<String>? = null

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
        val app = sbn.packageName ?: return
        if (app == packageName || !SmsForwarder.forwarding(context) || blockedPackage(app)) {
            return
        }
        val notification = sbn.notification ?: return
        // An ongoing one (a sync, a download) and a group's summary line are not money moves.
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
        if (text.isEmpty() || !DOLLARS.containsMatchIn(text) || !MONEY_WORD.containsMatchIn(text)) {
            return
        }
        val label = label(app)
        if (blockedLabel(label) || app in browsers()) {
            return
        }
        Log.i(TAG, "Money notification from $app ($label)")
        // Listed in the app's settings (new ones on), whether or not it goes.
        val on = MoneyApps.saw(context, app, label)
        // Android posts a notification again when it is updated: the same one, same words, once.
        if (repeated(context, sbn.key ?: "$app:${sbn.id}", text)) {
            return
        }
        if (!on) {
            SmsForwarder.skipped(context, FROM, sbn.postTime, "off", app, label)
            return
        }
        if (!SmsForwarder.enqueue(context, FROM, text, sbn.postTime, null, card = true, app = app, appName = label)) {
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

    private fun label(app: String): String {
        labels[app]?.let { return it }
        return try {
            val pm = packageManager
            @Suppress("DEPRECATION")
            val name = pm.getApplicationLabel(pm.getApplicationInfo(app, 0)).toString().trim().ifEmpty { app }
            labels[app] = name
            name
        } catch (_: Exception) {
            // Not visible to us or gone: the package stands for the name this time.
            app
        }
    }

    /** Every app that opens any web page (a browser), asked of the phone once. */
    private fun browsers(): Set<String> {
        browsers?.let { return it }
        val found = HashSet<String>()
        try {
            val pm = packageManager
            @Suppress("DEPRECATION")
            pm.queryIntentActivities(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_APP_BROWSER), 0)
                .forEach { found.add(it.activityInfo.packageName) }
            @Suppress("DEPRECATION")
            pm.queryIntentActivities(
                Intent(Intent.ACTION_VIEW, Uri.parse("https://example.com/")).addCategory(Intent.CATEGORY_BROWSABLE),
                PackageManager.MATCH_ALL,
            ).forEach { found.add(it.activityInfo.packageName) }
        } catch (_: Exception) {
        }
        browsers = found
        return found
    }

    companion object {
        private const val TAG = "MoneyListener"
        /** The sender the server knows these by; the app itself goes as "package" and "appName". */
        const val FROM = "app"
        private const val SEEN = "klok_money_seen"
        private const val WINDOW = 10 * 60 * 1000L

        /** A US-dollar amount: $25, $ 25.00, 25$, US$25, USD 25, 25 USD (not S$, A$, C$, HK$). */
        private val DOLLARS = Regex("""(?:\bUS\$|(?<![A-Za-z])\$|\bUSD)\s*\d|\d\s*(?:USD\b|US\$|\$)""", RegexOption.IGNORE_CASE)

        /** A word that says money moved. */
        private val MONEY_WORD = Regex(
            """\b(spent|charged|paid|payment|purchased?|debit(ed)?|transaction|refund(ed)?|reversed|added|deposit(ed)?|received|credited|top[\s-]?up|topped\s+up|loaded|funded|transfer(red)?|withdraw(n|al)?)\b""",
            RegexOption.IGNORE_CASE,
        )

        /** SMS, chat, social, email, browsers and the phone's own system: never read. */
        private val BLOCKED = setOf(
            "android",
            // SMS and chat
            "com.google.android.apps.messaging", "com.samsung.android.messaging", "com.whatsapp", "com.whatsapp.w4b",
            "com.facebook.orca", "com.facebook.mlite", "org.telegram.messenger", "org.telegram.messenger.web",
            "org.telegram.plus", "org.thunderdog.challegram", "com.viber.voip", "org.thoughtcrime.securesms",
            "com.imo.android.imoim", "com.imo.android.imoimbeta", "com.imo.android.imoimlite",
            "com.discord", "com.Slack", "com.microsoft.teams", "com.microsoft.teams2",
            // Social
            "com.instagram.android", "com.instagram.lite", "com.instagram.barcelona", "com.facebook.katana",
            "com.facebook.lite", "com.twitter.android", "com.twitter.android.lite", "com.snapchat.android",
            // Email
            "com.google.android.gm", "com.google.android.gm.lite", "com.microsoft.office.outlook",
            "com.yahoo.mobile.client.android.mail", "com.readdle.spark", "com.samsung.android.email.provider",
            // Browsers (any other is found by asking the phone which apps open web pages)
            "com.sec.android.app.sbrowser", "com.microsoft.emmx", "com.opera.browser", "com.opera.mini.native",
            "com.brave.browser", "com.duckduckgo.mobile.android", "com.UCMobile.intl", "com.vivaldi.browser",
            "com.kiwibrowser.browser", "com.mi.globalbrowser",
            // The phone's own system and Google Play services
            "com.google.android.gms", "com.google.android.gsf", "com.google.android.googlequicksearchbox",
        )
        private val BLOCKED_PREFIX = listOf("com.android.", "com.google.android.gms.", "com.chrome.", "org.mozilla.", "com.miui.", "com.coloros.", "com.oplus.")

        /** The same kinds by the app's name, for a copy or a phone maker's own version. */
        private val BLOCKED_NAME = Regex(
            """\b(messages|messaging|messenger|sms|whatsapp|telegram|viber|signal|imo|instagram|facebook|twitter|snapchat|discord|slack|teams|gmail|outlook|mail|email|e-mail|browser|chrome|firefox|system ui)\b""",
            RegexOption.IGNORE_CASE,
        )

        fun blockedPackage(app: String): Boolean = app in BLOCKED || BLOCKED_PREFIX.any { app.startsWith(it) }

        fun blockedLabel(label: String): Boolean = label.trim().equals("X", ignoreCase = true) || BLOCKED_NAME.containsMatchIn(label)

        /**
         * True when this notification (its key) with these same words was already handled in the
         * last ten minutes; else notes it now. Kept on disk, so a restart of the listener does not
         * send an update twice.
         */
        @Synchronized
        fun repeated(context: Context, key: String, text: String): Boolean {
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

/**
 * The card and bank apps this phone has seen a dollar notification from, each on or off here, and
 * one switch for all of it (on unless switched off). A new app starts on: the server still asks
 * the Owner before its notifications count.
 */
object MoneyApps {
    private const val PREFS = "klok_money_apps"

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun apps(context: Context): JSONObject =
        try {
            JSONObject(prefs(context).getString("apps", "{}") ?: "{}")
        } catch (_: Exception) {
            JSONObject()
        }

    fun allOn(context: Context): Boolean = prefs(context).getBoolean("on", true)

    /** Notes the app (its name, now); true when its notifications may go (all on, and it on). */
    @Synchronized
    fun saw(context: Context, app: String, label: String): Boolean {
        val all = apps(context)
        val entry = all.optJSONObject(app) ?: JSONObject().put("on", true)
        entry.put("label", label).put("seen", System.currentTimeMillis())
        all.put(app, entry)
        prefs(context).edit().putString("apps", all.toString()).commit()
        return allOn(context) && entry.optBoolean("on", true)
    }

    /** For the app's settings: {"on": all, "apps": [{package, label, on, seen}], newest first}. */
    @Synchronized
    fun list(context: Context): String {
        val all = apps(context)
        val rows = ArrayList<JSONObject>()
        val names = all.keys()
        while (names.hasNext()) {
            val app = names.next()
            val entry = all.optJSONObject(app) ?: continue
            rows.add(
                JSONObject().put("package", app).put("label", entry.optString("label", app))
                    .put("on", entry.optBoolean("on", true)).put("seen", entry.optLong("seen")),
            )
        }
        rows.sortByDescending { it.optLong("seen") }
        val out = JSONArray()
        rows.forEach { out.put(it) }
        return JSONObject().put("on", allOn(context)).put("apps", out).toString()
    }

    @Synchronized
    fun setApp(context: Context, app: String, on: Boolean) {
        val all = apps(context)
        val entry = all.optJSONObject(app) ?: JSONObject().put("label", app).put("seen", 0L)
        entry.put("on", on)
        all.put(app, entry)
        prefs(context).edit().putString("apps", all.toString()).commit()
    }

    fun setAll(context: Context, on: Boolean) {
        prefs(context).edit().putBoolean("on", on).commit()
    }
}
