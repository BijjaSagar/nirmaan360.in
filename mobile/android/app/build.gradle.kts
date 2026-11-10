import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// The Play upload key. The key and its passwords never go in the repository: they are read from
// android/key.properties on a build machine, or from ~/.nirmaan360/upload-key.properties on the
// owner's Mac. Each file gives storeFile, storePassword, keyAlias and keyPassword.
val uploadKey = Properties().apply {
    listOf(
        rootProject.file("key.properties"),
        File(System.getProperty("user.home"), ".nirmaan360/upload-key.properties"),
    ).firstOrNull { it.isFile }?.inputStream()?.use { load(it) }
}

// The phone APK, shared from phone to phone, stays on the debug key it has always used, so phones
// that have it keep updating in place. Moving those phones to Play is the one reinstall.
val isPhoneApk = project.hasProperty("phoneApk")

android {
    namespace = "in.nirmaan360.nirmaan360"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "in.nirmaan360.app"
        minSdk = 26 // Android 8.0, NFR-12
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName

        // `flutter build apk --android-project-arg phoneApk=true --android-project-arg disable-abi-filtering=true`
        // (the second stops Flutter resetting these filters) builds the one APK
        // shared from phone to phone. Phones run ARM only, so x86_64 (emulators, Chromebooks) is left out.
        if (isPhoneApk) {
            ndk { abiFilters += listOf("armeabi-v7a", "arm64-v8a") }
        }
    }

    // The phone APK keeps its native libraries compressed, so the file is about half the size and
    // fits a chat message. The phone unpacks them once at install. Store builds keep the default.
    packaging {
        jniLibs {
            useLegacyPackaging = isPhoneApk
        }
    }

    signingConfigs {
        if (uploadKey.getProperty("storeFile") != null) {
            create("upload") {
                storeFile = file(uploadKey.getProperty("storeFile"))
                storePassword = uploadKey.getProperty("storePassword")
                keyAlias = uploadKey.getProperty("keyAlias")
                keyPassword = uploadKey.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            // The Play bundle is signed with the upload key. Without the key file (another
            // machine, or the phone APK) the build falls back to the debug key, which phones
            // accept and Play refuses.
            signingConfig = signingConfigs.findByName("upload")?.takeUnless { isPhoneApk }
                ?: signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
