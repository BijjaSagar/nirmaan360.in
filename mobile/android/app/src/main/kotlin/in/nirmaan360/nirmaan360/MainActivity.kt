package `in`.nirmaan360.nirmaan360

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        createAlertsChannel()
    }

    /**
     * The channel push notifications are posted to (see the manifest's
     * default_notification_channel_id). Android 8 and later need it to exist
     * before the first message arrives, and it is made here so it always does:
     * a message can only come after the app has been opened and signed in.
     */
    private fun createAlertsChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val channel = NotificationChannel(
            ALERTS_CHANNEL_ID,
            getString(R.string.notification_channel_name),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = getString(R.string.notification_channel_description)
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }

    companion object {
        const val ALERTS_CHANNEL_ID = "n360_alerts"
    }
}
