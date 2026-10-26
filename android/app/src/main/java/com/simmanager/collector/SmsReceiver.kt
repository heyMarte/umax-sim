package com.simmanager.collector
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import org.json.JSONObject
import java.util.UUID

class SmsReceiver: BroadcastReceiver() {
 override fun onReceive(context:Context,intent:Intent){
 if(intent.action!=Telephony.Sms.Intents.SMS_RECEIVED_ACTION||!context.getSharedPreferences("config",0).getBoolean("enabled",false))return
 val parts=Telephony.Sms.Intents.getMessagesFromIntent(intent)
 if(parts.isEmpty())return
 val sub=intent.getIntExtra("android.telephony.extra.SUBSCRIPTION_INDEX",intent.getIntExtra("subscription",-1))
 val id=UUID.randomUUID().toString()
 val stamp=runCatching{SimLifecycle.receipt(context,sub)}.getOrDefault(ReceiptBinding(null,null))
 val event=JSONObject().put("event_id",id).put("subscription_id",if(sub>=0)sub else JSONObject.NULL).put("sim_token",stamp.token?:JSONObject.NULL).put("binding_version",stamp.version?:JSONObject.NULL).put("sender",parts[0].originatingAddress?:"").put("body",parts.joinToString(""){it.messageBody?:""}).put("received_at",parts[0].timestampMillis/1000.0)
 Queue(context).use{it.add(id,event)}
 ReceiveCheck.record(context,sub,event.getString("body"))
 context.getSharedPreferences("config",0).edit().putLong("last_received_at",System.currentTimeMillis()).apply()
 Recovery.ensure(context)
 CollectorService.requestSync()
 }
}
class BootReceiver:BroadcastReceiver(){override fun onReceive(c:Context,i:Intent){if(i.action==Intent.ACTION_BOOT_COMPLETED&&c.getSharedPreferences("config",0).getBoolean("enabled",false)){try{Recovery.ensure(c)}catch(_:Exception){c.getSharedPreferences("config",0).edit().putString("result","开机启动受限，请打开应用").apply()}}}}
