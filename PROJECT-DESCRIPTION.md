# 仓库介绍与发布文案

## 仓库名

umax-sim

## GitHub / Gitee简短描述

自部署的手机卡与验证码管理工具：Android采集、双卡管理、号码与店铺关联、多人权限、余额充值记录及可选微信通知。PHP + MySQL/MariaDB。

## English description

Self-hosted SIM card and verification-code management with an Android collector, dual-SIM device status, optional shop labels, team permissions, and balance records. PHP + MySQL/MariaDB.

## 建议标签

sim-manager, android, sms, otp, self-hosted, php, mysql, dual-sim

## 首次发布说明要点

- 基于现有后台0.5.11和App0.5.13整理；本次版本0.6.0。
- 部署者使用自己的服务器和手机，平台/店铺为号码用途标记。
- 明确国家/号码支持范围、Android最低版本和仍待验证的厂商兼容性。
- Android安装失败的备用流程为系统浏览器下载后覆盖安装，不卸载已有App。
- 源码包含安装、手机接入、备份与升级、可选微信配置、开发和贡献文档。
- 原站点默认配置已去除，使用MIT许可证；维护者签名私钥单独保管，安装验证结果见docs/VALIDATION.md。

不要把“支持全部Android机型”“微信强提醒保证到达”“支持所有国家与平台”等未验证内容写进宣传。
