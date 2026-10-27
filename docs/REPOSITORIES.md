# GitHub与Gitee同步

建议GitHub作为主要贡献入口，Gitee作为国内代码镜像。两个平台发布相同提交与版本tag，避免同一补丁在两边分别合并造成历史分叉。

维护者先创建两个空仓库，不勾选自动生成README/许可证，然后在已审查的本地仓库配置：

```bash
git remote add origin git@github.com:marte95/umax-sim.git
git remote add gitee git@gitee.com:umaxai/umax-sim.git
git push -u origin main
git push gitee main
git push origin v0.6.0
git push gitee v0.6.0
```

只使用自己的已配置SSH身份或安全的系统凭据管理器。不能把访问令牌、私钥或密码写进remote URL、脚本、README或聊天。

本仓库不是整个开发工作区。首次发布前运行检查和打包，核对文件清单；只有允许的源码和文档进入Git历史。已发布到一边不等于另一边已经同步，分别检查提交和tag。
