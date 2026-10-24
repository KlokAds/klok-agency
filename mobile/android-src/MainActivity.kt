package com.klokads.finance

import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/** A FragmentActivity for the fingerprint prompt; a channel for the SMS queue. */
class MainActivity : FlutterFragmentActivity() {
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
