# 手机预览（局域网）

手机打不开 `vialcovepeptidescom.local`，因为这个网址只写在电脑的 hosts 文件里。`phone_preview.py` 在电脑上开一个转发入口：手机打开 `http://<电脑的局域网地址>:17850/`，它把请求转给本地网站，并把网页里的网址换成手机能打开的地址。WordPress 的设置不改。

## 第一次使用：防火墙（管理员身份运行一次）

```powershell
New-NetFirewallRule -DisplayName "Peptide phone preview 17850" -Direction Inbound -Protocol TCP -LocalPort 17850 -RemoteAddress LocalSubnet -Program "C:\Users\JingYue\AppData\Local\Programs\Python\Python312\python.exe" -Action Allow -Profile Private
```

只允许同一个局域网里的设备，用的是“专用网络”。

## 每次使用

1. 双击 `start.cmd`（窗口不要关）。窗口第一行会显示手机要打开的地址，例如 `http://192.168.0.13:17850/`。
2. 手机连同一个 Wi-Fi，用浏览器打开这个地址；后台是地址后面加 `/wp-admin`。
3. 用完关掉窗口就停止了。

## 说明

- 只服务局域网里的设备（私有地址），外网访问不到。
- 手机上登录、加购、结账都走同一个本地网站和数据库。
- 电脑的局域网地址变了（例如重启路由器后），窗口里显示的就是新地址。
- 端口可以换：`start.cmd 17851`。换了端口要再加一条防火墙规则。
