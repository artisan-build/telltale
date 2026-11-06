plugins {
    kotlin("jvm") version "2.1.20"
    application
}
repositories { mavenCentral() }
val packageDirectory = projectDir.resolve("../../..").canonicalFile
val androidSdk = System.getenv("ANDROID_HOME") ?: System.getenv("ANDROID_SDK_ROOT")
    ?: error("Android SDK required to compile the actual platform adapter")
dependencies {
    compileOnly(files("$androidSdk/platforms/android-35/android.jar"))
    implementation("org.json:json:20250107")
}
kotlin {
    jvmToolchain(17)
    sourceSets.main {
        kotlin.srcDir(packageDirectory.resolve("resources/android"))
        kotlin.srcDir(projectDir.parentFile)
        kotlin.exclude("android-build/**")
    }
}
application { mainClass.set("com.artisanbuild.plugins.telltale_crashes.AndroidHarness") }
tasks.named<JavaExec>("run") { args(packageDirectory.absolutePath) }
layout.buildDirectory.set(packageDirectory.resolve(".native-build/gradle"))
