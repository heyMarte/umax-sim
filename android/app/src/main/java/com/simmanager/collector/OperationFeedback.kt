package com.simmanager.collector
import android.app.*
import android.content.*
import android.os.Build
import androidx.core.app.NotificationManagerCompat
/** Only explicit remote commands and terminal user tests use this channel. Never heartbeat noise. */
object OperationFeedback {
 fun notify(context:Context,title:String,message:String,id:Int){
  runCatching{
   val manager=context.getSystemService(NotificationManager::class.java)
   if(!NotificationManagerCompat.from(context).areNotificationsEnabled()||(Build.VERSION.SDK_INT>=33&&context.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS)!=android.content.pm.PackageManager.PERMISSION_GRANTED))return
   if(Build.VERSION.SDK_INT>=26)manager.createNotificationChannel(NotificationChannel("operations","Umax 操作结果",NotificationManager.IMPORTANCE_DEFAULT))
   val open=PendingIntent.getActivity(context,id,Intent(context,MainActivity::class.java),PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
   val notification=if(Build.VERSION.SDK_INT>=26)Notification.Builder(context,"operations") else Notification.Builder(context)
   manager.notify(id,notification.setSmallIcon(android.R.drawable.stat_notify_sync).setContentTitle(title).setContentText(message).setStyle(Notification.BigTextStyle().bigText(message)).setContentIntent(open).setAutoCancel(true).build())
  }
 }
 fun remote(context:Context,id:Long,generation:String,state:String,message:String){
  val p=context.getSharedPreferences("config",0)
  if(generation!=p.getString("config_generation","legacy"))return
  val start=state=="running";val now=System.currentTimeMillis()
  val edit=p.edit().putLong("remote_refresh_id",id).putString("remote_refresh_generation",generation).putString("remote_refresh_state",state).putString("remote_refresh_result",message)
  if(start)edit.putLong("remote_refresh_started_at",now).putLong("remote_refresh_finished_at",0)else edit.putLong("remote_refresh_finished_at",now)
  edit.apply()
  if(!start){val signature="$generation:${remoteFeedbackSignature(id,state)}";if(p.getString("remote_refresh_notified","")!=signature){p.edit().putString("remote_refresh_notified",signature).apply();notify(context,if(state=="success")"✓ 手机状态上报成功"else "! 手机状态上报失败",message,4901)}}
 }
}
