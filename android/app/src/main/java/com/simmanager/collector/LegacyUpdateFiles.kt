package com.simmanager.collector

import java.io.File
import java.io.FileOutputStream
import java.security.MessageDigest
import java.util.UUID

internal object UpdateHandoffPolicy {
 fun systemFile(sdk:Int,requested:Boolean)=sdk in 23..25||requested
 fun downloadPage(address:String):String?=ConnectionConfig.url(address)?.let{"$it/a"}
}

/** Copies are independent of the downloader's temporary file. External installers may
 * retain their URI while our Activity or process is destroyed; only later TTL cleanup removes them. */
internal class UpdateFileStore(private val root:File,private val clock:()->Long=System::currentTimeMillis) {
 companion object { const val TTL=172800000L }
 fun verified(file:File,size:Long,sha256:String):Boolean=runCatching {
  if(!file.isFile||size !in 1..UpdatePolicy.MAX_SIZE||file.length()!=size)return false
  val digest=MessageDigest.getInstance("SHA-256");var total=0L
  file.inputStream().use{input->val b=ByteArray(32768);while(true){val n=input.read(b);if(n<0)break;total+=n;if(total>size)return false;digest.update(b,0,n)}}
  UpdatePolicy.content(total,size,digest.digest().joinToString(""){"%02x".format(it)},sha256)
 }.getOrDefault(false)
 fun stage(source:File,size:Long,sha256:String):File {
  check(verified(source,size,sha256)){"APK 大小或 SHA-256 校验失败"}
  check(root.isDirectory||root.mkdirs()){"无法创建更新缓存"}
  val name="install-${UUID.randomUUID()}";val part=File(root,"$name.part");val target=File(root,"$name.apk")
  try {
   source.inputStream().use{input->FileOutputStream(part).use{out->val b=ByteArray(32768);var total=0L;while(true){val n=input.read(b);if(n<0)break;total+=n;check(total<=size){"APK 大小已变更"};out.write(b,0,n)};out.fd.sync()}}
   check(verified(part,size,sha256)){"APK 大小或 SHA-256 校验失败"}
   check(part.renameTo(target)){"无法保存已校验安装包"};target.setLastModified(clock());return target
  }catch(e:Exception){part.delete();target.delete();throw e}
 }
 fun cleanup(protectedName:String?) {
  val now=clock()
  root.listFiles()?.asSequence()?.filter{it.isFile&&it.name!=protectedName&&it.name.startsWith("install-")&&(it.extension=="apk"||it.extension=="part")&&now-it.lastModified()>TTL}
   ?.sortedBy{it.lastModified()}?.take(32)?.forEach{it.delete()}
 }
}
