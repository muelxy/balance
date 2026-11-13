package com.mulai.balancelive

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit

/**
 * Keeps the Android process important while a WebView LiveKit microphone session is active,
 * holds a partial CPU wake lock, and refreshes the authenticated PHP online heartbeat.
 * It does not replace the WebRTC audio transport; LiveKit remains in the WebView.
 */
class LiveKeepAliveService : Service() {
    private var wakeLock: PowerManager.WakeLock? = null
    private val executor: ScheduledExecutorService = Executors.newSingleThreadScheduledExecutor()
    private var heartbeatTask: ScheduledFuture<*>? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val baseUrl = intent?.getStringExtra(EXTRA_URL)?.trim().orEmpty()
        val token = intent?.getStringExtra(EXTRA_TOKEN).orEmpty()
        if (!baseUrl.startsWith("https://") || token.length < 20) {
            stopSelf(startId)
            return START_NOT_STICKY
        }

        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(this, CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(this)
        }
        val notification = builder
            .setSmallIcon(android.R.drawable.ic_btn_speak_now)
            .setContentTitle("Balance Live sedang aktif")
            .setContentText("Sesi audio tetap terhubung saat aplikasi di latar belakang")
            .setCategory(Notification.CATEGORY_SERVICE)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .build()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                NOTIFICATION_ID, notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE or ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK
            )
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }

        if (wakeLock?.isHeld != true) {
            val pm = getSystemService(POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "BalanceLive:AudioSession").apply {
                setReferenceCounted(false)
                acquire()
            }
        }

        heartbeatTask?.cancel(false)
        heartbeatTask = executor.scheduleAtFixedRate(
            { sendHeartbeat(baseUrl, token) }, 0, 10, TimeUnit.SECONDS
        )
        return START_NOT_STICKY
    }

    private fun sendHeartbeat(baseUrl: String, token: String) {
        var connection: HttpURLConnection? = null
        try {
            val separator = if (baseUrl.contains("?")) "&" else "?"
            val encoded = URLEncoder.encode(token, "UTF-8")
            connection = (URL(baseUrl + separator + "a=heartbeat&t=" + encoded).openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = 8000
                readTimeout = 8000
                useCaches = false
                setRequestProperty("Cache-Control", "no-cache")
            }
            connection.inputStream.use { it.readBytes() }
        } catch (_: Exception) {
            // A missed pulse is harmless; the next 10-second pulse retries automatically.
        } finally {
            connection?.disconnect()
        }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID, "Sesi audio Balance Live", NotificationManager.IMPORTANCE_LOW
            ).apply { description = "Menjaga sesi siaran audio Balance Live tetap aktif" }
            (getSystemService(NOTIFICATION_SERVICE) as NotificationManager).createNotificationChannel(channel)
        }
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        // Swiping the app away from Recents is treated as an explicit app exit.
        stopSelf()
        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        heartbeatTask?.cancel(true)
        executor.shutdownNow()
        wakeLock?.let { if (it.isHeld) it.release() }
        wakeLock = null
        stopForeground(STOP_FOREGROUND_REMOVE)
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        const val EXTRA_URL = "server_url"
        const val EXTRA_TOKEN = "session_token"
        private const val CHANNEL_ID = "balance_live_audio"
        private const val NOTIFICATION_ID = 7412
    }
}
