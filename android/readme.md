# 📑 Índice de Procedimientos

* 💻 1. Preparación del Sistema Operativo (Kali Linux)
* ⚙️ 2. Instalación del Android SDK y Toolchain
* 🚀 3. Inicialización del Proyecto Flutter
* 📦 4. Configuración de Dependencias y Permisos
* 📝 5. Inyección del Código Fuente (Persistencia)
* 🛠️ 6. Compilación y Extracción del Binario

---

# 💻 1. Preparación del Sistema Operativo (Kali Linux)

Es indispensable no ejecutar los comandos relacionados con Flutter bajo privilegios de superusuario (root). Todo el entorno debe configurarse desde un usuario estándar.

## Instalación de dependencias base y OpenJDK LTS

Se requiere OpenJDK 21 para evitar fallos de construcción con Gradle.

```bash
sudo apt update
sudo apt install curl git unzip xz-utils zip libglu1-mesa openjdk-21-jdk -y

# Forzar uso de OpenJDK 21
sudo update-alternatives --set java /usr/lib/jvm/java-21-openjdk-amd64/bin/java
sudo update-alternatives --set javac /usr/lib/jvm/java-21-openjdk-amd64/bin/javac
```

## Clonación del motor Flutter

```bash
mkdir -p ~/development
cd ~/development
git clone https://github.com/flutter/flutter.git -b stable

# Variables de entorno
echo 'export PATH="$PATH:$HOME/development/flutter/bin"' >> ~/.zshrc
source ~/.zshrc
```

---

# ⚙️ 2. Instalación del Android SDK y Toolchain

```bash
mkdir -p ~/Android/Sdk/cmdline-tools
cd ~/Android/Sdk/cmdline-tools

wget https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip -O cmdline.zip
unzip cmdline.zip
mv cmdline-tools latest

# Variables de entorno
echo 'export ANDROID_HOME="$HOME/Android/Sdk"' >> ~/.zshrc
echo 'export PATH="$PATH:$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools"' >> ~/.zshrc
source ~/.zshrc

# Instalación SDK
yes | sdkmanager --licenses
sdkmanager "platform-tools" "platforms;android-36" "build-tools;34.0.0" "build-tools;28.0.3"

# Vinculación con Flutter
flutter config --android-sdk "$HOME/Android/Sdk"
yes | flutter doctor --android-licenses

flutter doctor
```

---

# 🚀 3. Inicialización del Proyecto Flutter

```bash
cd ~/development
flutter create qrscan
cd qrscan
```

---

# 📦 4. Configuración de Dependencias y Permisos

## Adición de paquetes

```bash
flutter pub add mobile_scanner
flutter pub add otp
flutter pub add shared_preferences
```

---

# 📱 Configuración de Permisos y SDK (Android)

## 1. Ajustar el SDK Mínimo

Abrir el archivo `android/app/build.gradle`. Localizar el bloque `defaultConfig` y modificar el valor de `minSdkVersion` (el escáner requiere API 21).

```gradle
defaultConfig {
    applicationId "com.example.qrscan"
    minSdkVersion 21 // <-- VALOR CRÍTICO A MODIFICAR
    targetSdkVersion flutter.targetSdkVersion
    versionCode flutterVersionCode.toInteger()
    versionName flutterVersionName
}
```

## 2. Habilitar hardware de cámara

Abrir el archivo `android/app/src/main/AndroidManifest.xml` e inyectar el permiso estrictamente por encima del nodo `<application>`.

```xml
<uses-permission android:name="android.permission.CAMERA" />
```

---

# 📝 5. Inyección del Código Fuente (Persistencia)

Vaciar el archivo generado por defecto y abrir el editor:

```bas
nano lib/main.dart
```

Pegar el siguiente bloque lógico que incluye el gestor de estados, la UI y el guardado en base de datos local JSON:

[main.dart](/android/main.dart)

Guardar los cambios 

---

# 🛠️ 6. Compilación y Extracción del Binario

Limpiar la caché del framework para forzar la vinculación de las nuevas dependencias instaladas y empaquetar el ejecutable.

```bash
flutter clean
flutter pub get
flutter build apk --release
```

## 📦 Ubicación final y exportación

Transferir el archivo `.apk` finalizado a la ruta principal del usuario para facilitar su extracción hacia el dispositivo físico de auditoría/pruebas.

```bash
cp build/app/outputs/flutter-apk/app-release.apk ~/qrscan-release-final.apk
ls -lh ~/qrscan-release-final.apk
```
## Para facilitar el POC puedes descargar el apk en el release del proyecto 
