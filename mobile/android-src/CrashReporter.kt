package com.klokads.finance

import android.app.Application
import android.content.Context
import android.os.Build
import org.json.JSONObject
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter
import java.net.HttpURLConnection
import java.net.URL

/**
 * Catches a crash of the app itself (before Flutter even starts), writes it to a file and
 * sends it to the server: at once if it can, else on the next start. So a crash on a phone
 * can be fixed without a cable.
 */
class FinanceApplication : Application() {
    override fun attachBaseContext(base: Context) {
        super.attachBaseContext(base)
        CrashReporter.install(base)
    }

    override fun onCreate() {
        super.onCreate()
        Thread { CrashReporter.sendSaved(this) }.start()
    }
}

object CrashReporter {
    private const val URL_CRASH = "https://billing.klokads.com/hooks/app-crash"

    private fun file(context: Context) = File(context.filesDir, "last-crash.json")

    fun install(context: Context) {
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, error ->
            try {
                val trace = StringWriter().also { error.printStackTrace(PrintWriter(it)) }.toString()
                val report = body(context, "native", trace.take(18000))
                file(context).writeText(report.toString())
                // Try right away, for a few seconds at most.
                val sender = Thread { if (post(report)) file(context).delete() }
                sender.start()
                sender.join(3000)
            } catch (_: Throwable) {
            }
            previous?.uncaughtException(thread, error)
        }
    }

    fun sendSaved(context: Context) {
        try {
            val saved = file(context)
            if (saved.exists() && post(JSONObject(saved.readText()))) {
                saved.delete()
            }
        } catch (_: Throwable) {
        }
    }

    private fun body(context: Context, where: String, error: String): JSONObject {
        val version = try {
            context.packageManager.getPackageInfo(context.packageName, 0).versionName
        } catch (_: Throwable) {
            "?"
        }
        return JSONObject()
            .put("where", where)
            .put("error", error)
            .put("version", version)
            .put("device", "${Build.MANUFACTURER} ${Build.MODEL} · Android ${Build.VERSION.RELEASE}")
    }

    private fun post(report: JSONObject): Boolean {
        val connection = URL(URL_CRASH).openConnection() as HttpURLConnection
        return try {
            connection.requestMethod = "POST"
            connection.connectTimeout = 5000
            connection.readTimeout = 5000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(report.toString().toByteArray(Charsets.UTF_8)) }
            connection.responseCode in 200..299
        } catch (_: Throwable) {
            false
        } finally {
            connection.disconnect()
        }
    }
}
