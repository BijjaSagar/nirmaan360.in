package `in`.nirmaan360.nirmaan360

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        createNotificationChannels()
    }

    /**
     * The two channels the server posts to: "critical" for alerts that need
     * someone now, "updates" for everything else (see FcmGateway on the server
     * and the manifest's default_notification_channel_id). Android 8 and later
     * need them to exist before the first message arrives, and they are made
     * here so they always do: a message can only come after the app has been
     * opened and signed in. Making a channel again changes nothing the person set.
     */
    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(
                CRITICAL_CHANNEL_ID,
                getString(R.string.notification_channel_critical_name),
                NotificationManager.IMPORTANCE_HIGH,
            ).apply { description = getString(R.string.notification_channel_critical_description) },
        )
        manager.createNotificationChannel(
            NotificationChannel(
                UPDATES_CHANNEL_ID,
                getString(R.string.notification_channel_updates_name),
                NotificationManager.IMPORTANCE_DEFAULT,
            ).apply { description = getString(R.string.notification_channel_updates_description) },
        )
        // The single channel of the first test builds, which nothing posts to any more.
        manager.deleteNotificationChannel(OLD_CHANNEL_ID)
    }

    companion object {
        const val CRITICAL_CHANNEL_ID = "critical"
        const val UPDATES_CHANNEL_ID = "updates"
        private const val OLD_CHANNEL_ID = "n360_alerts"
    }
}
