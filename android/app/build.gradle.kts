plugins { id("com.android.application"); id("org.jetbrains.kotlin.android") }
android {
 namespace = "com.simmanager.collector"
 compileSdk = 35
 buildToolsVersion = "35.0.0"
 buildFeatures { buildConfig = true }
 defaultConfig { applicationId = "com.simmanager.collector"; minSdk = 23; targetSdk = 35; versionCode = 21; versionName = "0.6.0"; testInstrumentationRunner = providers.gradleProperty("updateRegressionRunner").orElse("com.simmanager.collector.RegressionRunner").get() }
 signingConfigs { getByName("debug") { enableV1Signing = true; enableV2Signing = true } }
 compileOptions { isCoreLibraryDesugaringEnabled = true; sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
 kotlinOptions { jvmTarget = "17" }
}
dependencies { coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4"); implementation("androidx.core:core:1.9.0"); implementation("com.journeyapps:zxing-android-embedded:4.3.0"); testImplementation("junit:junit:4.13.2") }
