package com.simmanager.collector

/** No hardware identifiers: Android subscription IDs and normalized phone hints only. */
internal data class SimObservation(val subscriptionId:Int,val slot:Int,val ready:Boolean,val phoneHint:String?)
internal data class SimGenerationRecord(val token:String,val slot:Int,val ready:Boolean,val knownPhoneHint:String?)
internal data class SimGenerationState(val configuration:String,val status:String,val revision:Long,val records:Map<Int,SimGenerationRecord>) {
 fun readyToken(subscriptionId:Int):String? = if(status=="ok")records[subscriptionId]?.takeIf{it.ready}?.token else null
}

/** Persist the last readable hint. Losing a hint/permission is not proof of replacement.
 * A silent physical swap with an unchanged subscription and no readable identity is unobservable.
 */
internal class SimGenerationPolicy(private val token:()->String={java.util.UUID.randomUUID().toString()}) {
 fun observe(previous:SimGenerationState?,configuration:String,status:String,rows:List<SimObservation>):SimGenerationState {
  val prior=previous?.takeIf{it.configuration==configuration}
  val records=if(status!="ok")prior?.records?:emptyMap() else {
   val byId=rows.groupBy{it.subscriptionId};val bySlot=rows.groupBy{it.slot}
   byId.filterKeys{it>=0}.mapValues{(id,duplicates)->
    val row=duplicates.first();val old=prior?.records?.get(id)
    val ready=row.ready&&duplicates.size==1&&row.slot>0&&bySlot[row.slot]?.size==1
    val hint=row.phoneHint?.takeIf{it.isNotBlank()}
    val changed=old==null||old.ready!=ready||(hint!=null&&old.knownPhoneHint!=hint)
    SimGenerationRecord(if(changed)token() else old!!.token,row.slot,ready,hint?:old?.knownPhoneHint)
   }
  }
  val changed=prior==null||prior.status!=status||prior.records!=records
  return SimGenerationState(configuration,status,if(changed)(previous?.revision?:0)+1 else prior!!.revision,records)
 }
}

/** Both snapshots must be the same observation, not merely reuse a subscription ID. */
internal fun bindingTokenMatches(sent:SimGenerationState,current:SimGenerationState,subscriptionId:Int,responseToken:String?):Boolean =
 sent.configuration==current.configuration&&sent.revision==current.revision&&responseToken!=null&&
 sent.readyToken(subscriptionId)==responseToken&&current.readyToken(subscriptionId)==responseToken

/** A null active list is also used for no active cards on older Android. Only
 * explicit ABSENT for every logical slot can turn it into authoritative zero.
 */
internal fun subscriptionSnapshotStatus(hasActiveList:Boolean,hasPermission:Boolean,slotStates:List<Int>?):String = when {
 !hasPermission->"permission_denied"
 hasActiveList->"ok"
 !slotStates.isNullOrEmpty()&&slotStates.all{it==1}->"ok"
 else->"unavailable"
}

/** Legacy active-subscription routing is distinct from physical SIM readiness, which stays unknown. */
internal fun subscriptionRoutingReady(sdk:Int,simState:Int?,active:Boolean,reason:String?):Boolean =
 simState==5||(sdk in 23..25&&simState==null&&active&&reason=="unsupported_android_version")
