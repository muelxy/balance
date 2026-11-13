package com.mulai.balancelive

import android.Manifest
import android.annotation.SuppressLint
import android.app.AlertDialog
import android.content.pm.PackageManager
import android.content.Intent
import android.net.Uri
import android.webkit.JavascriptInterface
import android.os.Bundle
import android.webkit.PermissionRequest
import android.webkit.WebChromeClient
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

class MainActivity : AppCompatActivity() {
    private lateinit var web: WebView
    private val prefs by lazy { getSharedPreferences("balance_live", MODE_PRIVATE) }
    private var pendingWebPermission: PermissionRequest? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        web = WebView(this).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.mediaPlaybackRequiresUserGesture = false
            settings.javaScriptCanOpenWindowsAutomatically = false
            settings.allowFileAccess = false
            settings.allowContentAccess = false
            webViewClient = WebViewClient()
            webChromeClient = object : WebChromeClient() {
                override fun onPermissionRequest(request: PermissionRequest) {
                    runOnUiThread {
                        val audioOnly = request.resources.isNotEmpty() &&
                            request.resources.all { it == PermissionRequest.RESOURCE_AUDIO_CAPTURE }

                        val expectedOrigin = prefs.getString("server_url", null)?.let {
                            android.net.Uri.parse(it)
                        }
                        val requestOrigin = request.origin
                        val trustedOrigin = expectedOrigin != null &&
                            requestOrigin.scheme == "https" &&
                            requestOrigin.host == expectedOrigin.host &&
                            requestOrigin.port == expectedOrigin.port

                        if (!audioOnly || !trustedOrigin) {
                            request.deny()
                            return@runOnUiThread
                        }

                        if (ContextCompat.checkSelfPermission(
                                this@MainActivity,
                                Manifest.permission.RECORD_AUDIO
                            ) == PackageManager.PERMISSION_GRANTED
                        ) {
                            request.grant(request.resources)
                        } else {
                            // Simpan permintaan WebRTC sampai izin Android dijawab pengguna.
                            if (pendingWebPermission != null) {
                                request.deny()
                                return@runOnUiThread
                            }
                            pendingWebPermission = request
                            ActivityCompat.requestPermissions(
                                this@MainActivity,
                                arrayOf(Manifest.permission.RECORD_AUDIO),
                                REQUEST_MIC
                            )
                        }
                    }
                }

                override fun onPermissionRequestCanceled(request: PermissionRequest) {
                    if (pendingWebPermission === request) pendingWebPermission = null
                }
            }
        }

        web.addJavascriptInterface(AndroidLiveBridge(), "BalanceAndroid")
        setContentView(web)
        val saved = prefs.getString("server_url", null)
        if (saved.isNullOrBlank()) {
            // Default domain requested for this Balance Live deployment; users can still change it.
            val defaultUrl = "https://serayalaras-sejahtera.com"
            prefs.edit().putString("server_url", defaultUrl).apply()
            loadServer(defaultUrl)
        } else {
            loadServer(saved)
        }
    }

    // Called only by the Balance Live page after microphone publishing succeeds.
    // The server URL is restricted to the HTTPS origin configured by the user.
    private inner class AndroidLiveBridge {
        @JavascriptInterface
        fun startLiveAudio(serverUrl: String, token: String): Boolean {
            return try {
                val requested = Uri.parse(serverUrl)
                val configured = prefs.getString("server_url", null)?.let { Uri.parse(it) }
                val trusted = requested.scheme == "https" && configured?.scheme == "https" &&
                    requested.host != null && requested.host == configured.host &&
                    requested.port == configured.port
                if (!trusted || token.length < 20) return false
                val intent = Intent(this@MainActivity, LiveKeepAliveService::class.java)
                    .putExtra(LiveKeepAliveService.EXTRA_URL, serverUrl)
                    .putExtra(LiveKeepAliveService.EXTRA_TOKEN, token)
                ContextCompat.startForegroundService(this@MainActivity, intent)
                true
            } catch (_: Exception) {
                false
            }
        }

        @JavascriptInterface
        fun stopLiveAudio() {
            stopService(Intent(this@MainActivity, LiveKeepAliveService::class.java))
        }
    }

    private fun showServerDialog() {
        val input = EditText(this).apply {
            hint = "https://domain-anda.com/"
            setSingleLine(true)
            setText(prefs.getString("server_url", ""))
        }
        val wrap = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 12, 48, 0)
            addView(input)
        }
        AlertDialog.Builder(this)
            .setTitle("Hubungkan Balance Live")
            .setMessage("Masukkan alamat website PHP Balance Live (wajib HTTPS).")
            .setView(wrap)
            .setCancelable(false)
            .setPositiveButton("Hubungkan") { _, _ ->
                val url = input.text.toString().trim()
                if (!url.startsWith("https://")) {
                    Toast.makeText(this, "Gunakan alamat HTTPS untuk keamanan mikrofon.", Toast.LENGTH_LONG).show()
                    showServerDialog()
                } else {
                    prefs.edit().putString("server_url", url).apply()
                    loadServer(url)
                }
            }
            .show()
    }

    private fun loadServer(raw: String) {
        val url = raw.trim().let {
            if (it.endsWith(".php")) it else it.trimEnd('/') + "/index.php"
        }
        web.loadUrl(url)
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode != REQUEST_MIC) return

        val request = pendingWebPermission
        pendingWebPermission = null
        val granted = grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

        if (request != null) {
            if (granted && !isFinishing) request.grant(request.resources) else request.deny()
        }
        if (!granted) {
            Toast.makeText(this, "Izin mikrofon diperlukan untuk berbicara di room.", Toast.LENGTH_LONG).show()
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (::web.isInitialized && web.canGoBack()) web.goBack() else super.onBackPressed()
    }

    override fun onDestroy() {
        pendingWebPermission?.deny()
        pendingWebPermission = null
        if (::web.isInitialized) {
            web.stopLoading()
            web.destroy()
        }
        super.onDestroy()
    }

    companion object {
        private const val REQUEST_MIC = 201
    }
}
