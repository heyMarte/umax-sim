# 开发与构建

源码布局为server/与android/。现有历史测试含本机绝对路径及外部测试环境依赖，开源整理时需分层修正，不能承诺克隆后全部直接运行。

## 后台

PHP8.2，数据库为MySQL/MariaDB；不使用Composer运行依赖。前端为原生JavaScript/CSS，已有Node.js内置测试器运行前端单元测试。

```bash
cd server
npm test
```

本地功能验证使用独立测试数据库和私有测试配置，可通过SIM_CONFIG、SIM_KEYRING及测试所支持的SIM_TEST_URL覆盖路径/地址。不能把测试套件指向生产站点；部分测试会新增、修改或删除数据。

统一无数据库检查入口为`bash scripts/check.sh`，覆盖PHP语法、纯函数与前端测试、文档链接和源码隐私。完整发布验证另外覆盖：PHP语法、前端单元测试、平台配置、权限范围、号码历史归属、空白站点安装、迁移和源码包隐私检查。数据库集成测试的环境准备单独说明。

## Android

现有工具链：JDK17、Gradle8.9、AGP8.7.3、Kotlin2.0.21、Android SDK35/Build Tools35.0.0；minSdk23，targetSdk35。

设置JAVA_HOME与ANDROID_HOME，在android目录执行：

```bash
./gradlew testDebugUnitTest assembleDebug assembleDebugAndroidTest lintDebug
```

生成的开发APK在`app/build/outputs/apk/debug/`。调试构建会使用当前开发者自己的debug keystore，不会自动获得项目现有发行APK的签名。

开源发行应使用固定维护者签名，从本机私有配置或CI受保护Secrets注入；仓库只提供变量名和构建说明，不提交keystore、密码、已签名运行配置或真实接入码。

包名为com.simmanager.collector。若派生项目更换签名或包名，须解释与已有安装的迁移关系；不要建议用户盲目卸载丢掉本机待上传队列。

## 发版

后台代码版本和AndroidversionCode分别管理。GitHub/Gitee应发布来自同一提交的源码tag。APK和部署ZIP放在Release附件，仓库不长期堆积各版APK、模拟器、SDK或缓存。

已知安装兼容性问题如实记录；模拟器验证不能替代所有厂商真机验证。发布说明列明实际验证设备和未验证范围。

## 依赖声明

Nayuki QR-Code-generator使用MIT；AndroidX Core、JourneyApps ZXing Embedded等依赖保留各自许可证。正式发行需附完整第三方声明和必要许可证文本，不能以项目许可证覆盖依赖自身的条款。
