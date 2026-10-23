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
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }
}
