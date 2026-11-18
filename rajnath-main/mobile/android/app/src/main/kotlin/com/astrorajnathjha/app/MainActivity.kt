package com.astrorajnathjha.app

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Small "arj/call" channel used by the in-app call screen:
 *  - keepScreenOn(bool): toggles FLAG_KEEP_SCREEN_ON (no wakelock permission needed).
 *  - requestBluetooth(): asks for BLUETOOTH_CONNECT on Android 12+ so call audio can route to
 *    Bluetooth headsets. Returns true when granted (or not needed).
 *  - callStarted({video, title}) / callEnded(): starts / stops [CallService], the foreground service that keeps
 *    the microphone and camera working while the app is in the background during a call.
 * Microphone and camera permissions are requested by flutter_webrtc itself.
 */
class MainActivity : FlutterActivity() {
    private var pendingBluetooth: MethodChannel.Result? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "arj/call").setMethodCallHandler { call, result ->
            when (call.method) {
                "keepScreenOn" -> {
                    if (call.arguments == true) {
                        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    } else {
                        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    }
                    result.success(null)
                }
                "requestBluetooth" -> requestBluetooth(result)
                "callStarted" -> {
                    val args = call.arguments as? Map<*, *>
                    try {
                        CallService.start(this, args?.get("video") == true, (args?.get("title") as? String) ?: "Consultation call")
                        result.success(true)
                    } catch (e: Exception) {
                        result.success(false)
                    }
                }
                "callEnded" -> {
                    CallService.stop(this)
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }

    private fun requestBluetooth(result: MethodChannel.Result) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
        ) {
            result.success(true)
            return
        }
        if (pendingBluetooth != null) {
            result.success(false)
            return
        }
        pendingBluetooth = result
        requestPermissions(arrayOf(Manifest.permission.BLUETOOTH_CONNECT), BLUETOOTH_REQUEST)
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == BLUETOOTH_REQUEST) {
            val granted = grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED
            pendingBluetooth?.success(granted)
            pendingBluetooth = null
        }
    }

    override fun onDestroy() {
        if (isFinishing) CallService.stop(this)
        super.onDestroy()
    }

    companion object {
        private const val BLUETOOTH_REQUEST = 4711
    }
}
