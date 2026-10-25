package com.klokads.finance

import android.os.Build
import android.os.Bundle
import android.view.View
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/** A FragmentActivity for the fingerprint prompt; a channel for the SMS queue. */
class MainActivity : FlutterFragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Android's autofill ("Suggest strong password") pops up over the page's fields and takes
        // the focus, so the keyboard closed after every letter. The app keeps its sign-in anyway.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            window.decorView.importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
        }
    }

    /**
     * Android writes the web cookies to the phone only now and then; an update or a kill in
     * between lost the newest "keep me signed in" cookie. Written whenever the app leaves the screen.
     */
    override fun onPause() {
        super.onPause()
        android.webkit.CookieManager.getInstance().flush()
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "klok/sms").setMethodCallHandler { call, result ->
            when (call.method) {
                "pending" -> result.success(SmsForwarder.pending(this))
                "flush" -> {
                    SmsForwarder.schedule(this)
                    SmsForwarder.keepAlive(this)
                    result.success(null)
                }
                "log" -> result.success(SmsForwarder.log(this))
                "clearLog" -> {
                    SmsForwarder.clearLog(this)
                    result.success(null)
                }
                "retry" -> result.success(SmsForwarder.retry(this, call.argument<String>("id") ?: ""))
                "beat" -> Thread { val ok = SmsForwarder.beat(this); runOnUiThread { result.success(ok) } }.start()
                "cleanLog" -> result.success(SmsForwarder.cleanLog(this, call.argument<Int>("days") ?: 30))
                "importInbox" -> {
                    val days = call.argument<Int>("days") ?: 3
                    Thread {
                        val count = try { SmsForwarder.importInbox(this, days) } catch (e: Exception) { -1 }
                        runOnUiThread { result.success(count) }
                    }.start()
                }
                "defaultIgnore" -> result.success(SmsForwarder.DEFAULT_IGNORE.joinToString("\n"))
                else -> result.notImplemented()
            }
        }
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "klok/update").setMethodCallHandler { call, result ->
            when (call.method) {
                "allowed" -> result.success(AppUpdater.allowed(this))
                "askToAllow" -> {
                    AppUpdater.askToAllow(this)
                    result.success(null)
                }
                "install" -> {
                    val url = call.argument<String>("url")
                    if (url == null) {
                        result.error("url", "no url", null)
                    } else {
                        AppUpdater.install(this, url) { status -> runOnUiThread { result.success(status) } }
                    }
                }
                else -> result.notImplemented()
            }
        }
    }
}
