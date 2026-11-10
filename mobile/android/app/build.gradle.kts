plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

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
        if (project.hasProperty("phoneApk")) {
            ndk { abiFilters += listOf("armeabi-v7a", "arm64-v8a") }
        }
    }

    // The phone APK keeps its native libraries compressed, so the file is about half the size and
    // fits a chat message. The phone unpacks them once at install. Store builds keep the default.
    packaging {
        jniLibs {
            useLegacyPackaging = project.hasProperty("phoneApk")
        }
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
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
