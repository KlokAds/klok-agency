package com.klokads.finance

import android.app.Activity
import android.app.KeyguardManager
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowManager
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
        // The app lock as the app keeps it ('bio' when never chosen; see Lock.mode in main.dart).
        val lock = getSharedPreferences("FlutterSharedPreferences", MODE_PRIVATE).getString("flutter.lock.mode", "bio")
        hideRecents(lock != "off")
    }

    /**
     * Android writes the web cookies to the phone only now and then; an update or a kill in
     * between lost the newest "keep me signed in" cookie. Written whenever the app leaves the screen.
     */
    override fun onPause() {
        super.onPause()
        android.webkit.CookieManager.getInstance().flush()
    }

    private var credentialResult: MethodChannel.Result? = null
    private var linkChannel: MethodChannel? = null
    private var pendingLink: String? = null

    /** A billing.klokads.com link opened while the app runs: shown in it. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        val link = ourLink(intent) ?: return
        linkChannel?.invokeMethod("open", link) ?: run { pendingLink = link }
    }

    /** The phone's own PIN / pattern / password screen came back. */
    @Deprecated("startActivityForResult keeps the prompt simple here")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == CREDENTIAL) {
            credentialResult?.success(resultCode == Activity.RESULT_OK)
            credentialResult = null
        }
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        pendingLink = ourLink(intent)
        linkChannel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "klok/link").apply {
            setMethodCallHandler { call, result ->
                when (call.method) {
                    // The link the app was opened with, once.
                    "initial" -> {
                        result.success(pendingLink)
                        pendingLink = null
                    }
                    else -> result.notImplemented()
                }
            }
        }
        // The phone's PIN / pattern when the finger is not read (a dirty or broken sensor):
        // some phones' fingerprint prompt has no way to it.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "klok/lock").setMethodCallHandler { call, result ->
            when (call.method) {
                "phoneCredential" -> {
                    val keyguard = getSystemService(KEYGUARD_SERVICE) as KeyguardManager
                    @Suppress("DEPRECATION")
                    val intent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && keyguard.isDeviceSecure) keyguard.createConfirmDeviceCredentialIntent("KlokOne", call.argument<String>("reason")) else null
                    if (intent == null) {
                        result.success(false)
                    } else {
                        credentialResult?.success(false)
                        credentialResult = result
                        @Suppress("DEPRECATION")
                        startActivityForResult(intent, CREDENTIAL)
                    }
                }
                // Whether the phone has a screen lock at all: only without one may the app open
                // with no fingerprint or PIN (a busy sensor must not count as "no lock").
                "deviceSecure" -> {
                    val keyguard = getSystemService(KEYGUARD_SERVICE) as KeyguardManager
                    result.success(if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) keyguard.isDeviceSecure else keyguard.isKeyguardSecure)
                }
                // Set from the app's lock: on, and Recents shows no picture of the books.
                "hideRecents" -> {
                    hideRecents(call.argument<Boolean>("on") ?: true)
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
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

    /**
     * The app lock covers only the app itself; the Recents screen kept a picture of the last page
     * (figures, clients, margins). Android 13+ can drop just that picture, so screenshots still
     * work; older phones need FLAG_SECURE, which also blocks screenshots while the lock is on.
     */
    private fun hideRecents(on: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            setRecentsScreenshotEnabled(!on)
        } else if (on) {
            window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        } else {
            window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
    }

    /** The link, only when it is a page of our own site (another app may send anything). */
    private fun ourLink(intent: Intent?): String? {
        val uri = intent?.data ?: return null
        return if (uri.scheme == "https" && uri.host == "billing.klokads.com") uri.toString() else null
    }

    companion object {
        private const val CREDENTIAL = 7301
    }
}
