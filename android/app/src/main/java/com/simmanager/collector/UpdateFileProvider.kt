package com.simmanager.collector

import android.content.ClipData
import android.content.Context
import android.content.Intent
import java.io.File
import androidx.core.content.FileProvider

class UpdateFileProvider:FileProvider()

/** The legacy file is internal and not writable by other applications. Only the
 * dedicated APK file becomes readable; the cache root already allows traversal
 * on API23. Configuration and queued messages keep their original permissions. */
internal fun updateInstallDirectory(context:Context):File = File(context.cacheDir,"verified-updates")

internal fun systemUpdateIntent(context:Context,file:File):Intent {
 if(android.os.Build.VERSION.SDK_INT==23){
  val root=updateInstallDirectory(context).canonicalFile
  require(file.parentFile?.canonicalFile==root&&file.extension=="apk"&&file.name.startsWith("install-")&&file.isFile){"更新文件无效，请打开下载页面"}
  check(root.setWritable(false,false)&&root.setWritable(true,true)&&root.setReadable(false,false)&&root.setReadable(true,true)&&root.setExecutable(true,false)&&file.setWritable(false,false)&&file.setReadable(true,false)){"无法准备系统安装文件，请打开下载页面"}
  return Intent(Intent.ACTION_VIEW).setDataAndType(android.net.Uri.fromFile(file),"application/vnd.android.package-archive")
   .putExtra(Intent.EXTRA_RETURN_RESULT,true)
 }
 val uri=FileProvider.getUriForFile(context,context.packageName+".updatefiles",file)
 return Intent(Intent.ACTION_VIEW).setDataAndType(uri,"application/vnd.android.package-archive")
  .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
  .putExtra(Intent.EXTRA_RETURN_RESULT,true)
  .apply{clipData=ClipData.newRawUri("已校验应用更新",uri)}
}
