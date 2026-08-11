package com.v2ray.ang.ui
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.Editable
import android.text.InputFilter
import android.text.InputType
import android.text.TextWatcher
import android.text.TextUtils
import android.view.Gravity
import android.view.KeyEvent
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnDynamicBackgroundView
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnPersianDate
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale
class BlueVpnSubscriptionsActivity:HelperBaseActivity(){
 private lateinit var content:LinearLayout;private lateinit var status:TextView;private lateinit var palette:BlueVpnPalette;private val handler=Handler(Looper.getMainLooper());private var busy=false;private var firstResume=true;private var otpChallengeId="";private var otpPhone="";private var otpBinding=false;private var authMode="sms";private var emailRegister=false;private var themeDarkAtCreate=true;private var renderedSessionState=false;private var draftPhone="";private var draftOtpCode="";private var draftEmail="";private var draftPassword="";private var draftBindingPhone="";private var draftBindingCode=""
 private val poll=object:Runnable{override fun run(){val id=BlueVpnAccountManager.pendingOrder(this@BlueVpnSubscriptionsActivity);if(id.isNotBlank()){checkOrder(id);handler.postDelayed(this,4000)}}}
 override fun onCreate(b:Bundle?){super.onCreate(b);palette=BlueVpnTheme.palette(this);themeDarkAtCreate=palette.dark;window.setBackgroundDrawable(ColorDrawable(palette.background));BlueVpnTheme.applySystemBars(this);setContentView(screen());render()}
 override fun onResume(){
  super.onResume()
  BlueVpnTheme.applySystemBars(this)
  if(BlueVpnTheme.isDark(this)!=themeDarkAtCreate){recreate();return}
  val returnedOrder=BlueVpnAccountManager.checkoutBrowserOrder(this)
  if(returnedOrder.isNotBlank()){closeCheckoutAfterReturn(returnedOrder)}
  if(firstResume){
   firstResume=false
   if(returnedOrder.isBlank()&&BlueVpnAccountManager.hasSession(this)){
    sync(true)
   }
  }else if(BlueVpnAccountManager.hasSession(this)!=renderedSessionState){render()}
  handler.removeCallbacks(poll)
  handler.post(poll)
 }
 override fun onPause(){handler.removeCallbacks(poll);super.onPause()}
 private fun screen():View{
  palette=BlueVpnTheme.palette(this)
  val loginScreen=!BlueVpnAccountManager.hasSession(this)
  val frame=FrameLayout(this).apply{setBackgroundColor(if(loginScreen)authBg() else palette.background)}
  if(loginScreen){
   val glowTop=View(this).apply{background=authGlowDrawable(74)}
   frame.addView(glowTop,FrameLayout.LayoutParams(dp(310),dp(310),Gravity.END or Gravity.TOP).apply{marginEnd=-dp(132);topMargin=-dp(90)})
   val glowBottom=View(this).apply{background=authGlowDrawable(48)}
   frame.addView(glowBottom,FrameLayout.LayoutParams(dp(300),dp(300),Gravity.START or Gravity.BOTTOM).apply{marginStart=-dp(130);bottomMargin=-dp(80)})
  }else{
   frame.addView(BlueVpnDynamicBackgroundView(this),FrameLayout.LayoutParams(-1,-1))
  }
  val root=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(dp(18),dp(12),dp(18),dp(20));layoutDirection=View.LAYOUT_DIRECTION_RTL}
  val h=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER_VERTICAL}
  val back=if(loginScreen){
   TextView(this).apply{
    text="بازگشت  ←";textSize=12.5f;gravity=Gravity.CENTER;setTextColor(authAccent());setTypeface(typeface,Typeface.BOLD);isClickable=true;isFocusable=true
    background=GradientDrawable().apply{cornerRadius=dp(14).toFloat();setColor(Color.parseColor("#0D0D0F"));setStroke(dp(1),Color.parseColor("#3B2518"))}
    setOnClickListener{finish()}
   }
  }else{
   button("بازگشت",palette.surfaceStrong).apply{setTextColor(palette.textPrimary);setOnClickListener{finish()}}
  }
  val title=TextView(this).apply{text="حساب BlueVPN";textSize=if(loginScreen)20f else 23f;setTextColor(if(loginScreen)Color.WHITE else palette.textPrimary);setTypeface(typeface,Typeface.BOLD);gravity=Gravity.END;includeFontPadding=false}
  h.addView(back,LinearLayout.LayoutParams(dp(92),dp(44)))
  h.addView(title,LinearLayout.LayoutParams(0,dp(50),1f))
  root.addView(h)
  status=TextView(this).apply{textSize=11.5f;setTextColor(if(loginScreen)authMuted() else palette.textMuted);gravity=Gravity.CENTER;setPadding(0,dp(5),0,dp(10));includeFontPadding=false;maxLines=3;ellipsize=TextUtils.TruncateAt.END}
  root.addView(status)
  content=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL}
  root.addView(ScrollView(this).apply{isFillViewport=true;overScrollMode=View.OVER_SCROLL_NEVER;addView(content)},LinearLayout.LayoutParams(-1,0,1f))
  frame.addView(root,FrameLayout.LayoutParams(-1,-1))
  return frame
 }
 private fun render(){
  val hasSession=BlueVpnAccountManager.hasSession(this)
  renderedSessionState=hasSession
  content.removeAllViews()
  if(hasSession)account() else auth()
 }
 private fun remember(field:EditText,onValue:(String)->Unit){
  field.addTextChangedListener(object:TextWatcher{
   override fun beforeTextChanged(s:CharSequence?,start:Int,count:Int,after:Int){}
   override fun onTextChanged(s:CharSequence?,start:Int,before:Int,count:Int){onValue(s?.toString().orEmpty())}
   override fun afterTextChanged(s:Editable?){}
  })
 }
 private fun auth(){
  status.setTextColor(authMuted())
  status.text=if(authMode=="sms")"ورود امن با کد یک‌بارمصرف ۶ رقمی" else "ورود یا ثبت‌نام با ایمیل"

  val authCard=MaterialCardView(this).apply{
   radius=dp(28).toFloat();cardElevation=dp(12).toFloat();strokeWidth=dp(1);strokeColor=Color.parseColor("#422315");setCardBackgroundColor(Color.parseColor("#E6080A0C"))
  }
  val box=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;gravity=Gravity.CENTER_HORIZONTAL;setPadding(dp(18),dp(24),dp(18),dp(24))}

  val logo=TextView(this).apply{
   text="B";textSize=25f;gravity=Gravity.CENTER;setTextColor(Color.parseColor("#160A03"));setTypeface(typeface,Typeface.BOLD);elevation=dp(8).toFloat()
   background=GradientDrawable(GradientDrawable.Orientation.TL_BR,intArrayOf(Color.parseColor("#FB923C"),Color.parseColor("#F97316"),Color.parseColor("#EA580C"))).apply{cornerRadius=dp(19).toFloat()}
  }
  box.addView(logo,LinearLayout.LayoutParams(dp(58),dp(58)))
  box.addView(TextView(this).apply{text="BlueVPN";textSize=14f;gravity=Gravity.CENTER;setTextColor(Color.parseColor("#FED7AA"));setTypeface(typeface,Typeface.BOLD);letterSpacing=.04f;setPadding(0,dp(10),0,dp(18))})

  val modeRow=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER;setPadding(dp(4),dp(4),dp(4),dp(4))
   background=GradientDrawable().apply{cornerRadius=dp(15).toFloat();setColor(Color.parseColor("#101114"));setStroke(dp(1),Color.parseColor("#292A2E"))}
  }
  modeRow.addView(archiveSegment("پیامک",authMode=="sms").apply{setOnClickListener{if(authMode!="sms"){authMode="sms";emailRegister=false;otpChallengeId="";render()}}},LinearLayout.LayoutParams(0,dp(42),1f).apply{marginEnd=dp(3)})
  modeRow.addView(archiveSegment("ایمیل",authMode=="email").apply{setOnClickListener{if(authMode!="email"){authMode="email";otpChallengeId="";otpBinding=false;render()}}},LinearLayout.LayoutParams(0,dp(42),1f).apply{marginStart=dp(3)})
  box.addView(modeRow,LinearLayout.LayoutParams(-1,dp(50)).apply{bottomMargin=dp(22)})

  if(authMode=="sms"){
   if(otpChallengeId.isBlank()){
    box.addView(archiveTitle("ورود به حساب کاربری"))
    box.addView(archiveSubtitle("شماره موبایل خود را وارد کنید تا کد ورود ۶ رقمی برایتان ارسال شود."),LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(20)})
    box.addView(archiveLabel("شماره موبایل"),LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(8)})
    val phoneField=archivePhoneField(smsPhoneNational(draftPhone.ifBlank{otpPhone}))
    val phone=phoneField.second.apply{
     imeOptions=EditorInfo.IME_ACTION_DONE
     remember(this){draftPhone=smsPhoneForApi(it)}
     setOnEditorActionListener{_,actionId,_->if(actionId==EditorInfo.IME_ACTION_DONE){requestOtp(smsPhoneForApi(text.toString()),false);true}else false}
    }
    box.addView(phoneField.first,LinearLayout.LayoutParams(-1,dp(56)))
    box.addView(archivePrimary("ارسال کد ورود").apply{setOnClickListener{requestOtp(smsPhoneForApi(phone.text.toString()),false)}},LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)})
   }else{
    val change=TextView(this).apply{text="→  تغییر شماره";textSize=11.5f;gravity=Gravity.START;setTextColor(authAccent());setTypeface(typeface,Typeface.BOLD);isClickable=true;setPadding(0,0,0,dp(10));setOnClickListener{otpChallengeId="";draftOtpCode="";render()}}
    box.addView(change,LinearLayout.LayoutParams(-1,-2))
    box.addView(archiveTitle("کد تأیید را وارد کنید"))
    box.addView(archiveSubtitle("کد ۶ رقمی ارسال‌شده به ${smsPhonePretty(otpPhone.ifBlank{draftPhone})} را وارد کنید."),LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(18)})
    val otpRow=archiveOtpRow{
     if(draftOtpCode.length==6)verifyOtp(smsPhoneForApi(otpPhone.ifBlank{draftPhone}),draftOtpCode,false)
    }
    box.addView(otpRow,LinearLayout.LayoutParams(-1,dp(58)))
    box.addView(archivePrimary("تأیید و ورود").apply{setOnClickListener{verifyOtp(smsPhoneForApi(otpPhone.ifBlank{draftPhone}),draftOtpCode,false)}},LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)})
    val resend=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER;setPadding(0,dp(15),0,0)}
    resend.addView(TextView(this).apply{text="کد را دریافت نکردید؟";textSize=11f;setTextColor(Color.parseColor("#A1A1AA"));gravity=Gravity.CENTER})
    resend.addView(TextView(this).apply{text="  ارسال مجدد";textSize=11f;setTextColor(authAccent());setTypeface(typeface,Typeface.BOLD);gravity=Gravity.CENTER;isClickable=true;setOnClickListener{otpChallengeId="";draftOtpCode="";requestOtp(smsPhoneForApi(otpPhone.ifBlank{draftPhone}),false)}})
    box.addView(resend)
   }
  }else{
   val emailMode=LinearLayout(this).apply{
    orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER;setPadding(dp(4),dp(4),dp(4),dp(4))
    background=GradientDrawable().apply{cornerRadius=dp(14).toFloat();setColor(Color.parseColor("#101114"));setStroke(dp(1),Color.parseColor("#292A2E"))}
   }
   emailMode.addView(archiveMiniSegment("ورود",!emailRegister).apply{setOnClickListener{if(emailRegister){emailRegister=false;render()}}},LinearLayout.LayoutParams(0,dp(38),1f).apply{marginEnd=dp(3)})
   emailMode.addView(archiveMiniSegment("ثبت‌نام",emailRegister).apply{setOnClickListener{if(!emailRegister){emailRegister=true;render()}}},LinearLayout.LayoutParams(0,dp(38),1f).apply{marginStart=dp(3)})
   box.addView(emailMode,LinearLayout.LayoutParams(-1,dp(46)).apply{bottomMargin=dp(20)})
   box.addView(archiveTitle(if(emailRegister)"ساخت حساب کاربری" else "ورود با ایمیل"))
   box.addView(archiveSubtitle(if(emailRegister)"ایمیل و یک رمز عبور حداقل ۸ کاراکتری تعیین کنید." else "ایمیل و رمز عبور حساب BlueVPN خود را وارد کنید."),LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(18)})

   box.addView(archiveLabel("ایمیل"),LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(8)})
   val email=archiveInput("example@email.com",InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS,true).apply{imeOptions=EditorInfo.IME_ACTION_NEXT;setText(draftEmail);remember(this){draftEmail=it}}
   box.addView(email,LinearLayout.LayoutParams(-1,dp(56)))
   box.addView(archiveLabel("رمز عبور"),LinearLayout.LayoutParams(-1,-2).apply{topMargin=dp(13);bottomMargin=dp(8)})
   val password=archiveInput("حداقل ۸ کاراکتر",InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD,true).apply{
    imeOptions=EditorInfo.IME_ACTION_DONE;setText(draftPassword);remember(this){draftPassword=it}
    setOnEditorActionListener{_,actionId,_->if(actionId==EditorInfo.IME_ACTION_DONE){emailAuth(email.text.toString(),text.toString(),emailRegister);true}else false}
   }
   box.addView(password,LinearLayout.LayoutParams(-1,dp(56)))
   box.addView(archivePrimary(if(emailRegister)"ثبت‌نام و ورود" else "ورود به BlueVPN").apply{setOnClickListener{emailAuth(email.text.toString(),password.text.toString(),emailRegister)}},LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)})
  }

  box.addView(TextView(this).apply{
   text="ورود روی همین دستگاه ذخیره می‌شود و اطلاعات اشتراک در پس‌زمینه همگام خواهد شد.";textSize=10f;gravity=Gravity.CENTER;setTextColor(Color.parseColor("#71717A"));setPadding(dp(8),dp(18),dp(8),0)
  })
  authCard.addView(box)
  content.addView(authCard,LinearLayout.LayoutParams(-1,-2).apply{topMargin=dp(4);bottomMargin=dp(20)})
 }
 private fun account(){
  val account=BlueVpnAccountManager.snapshot(this)
  status.text=
   if(account.subscriptionActive){
    "حساب شما آماده اتصال است"
   }else{
    "برای ادامه یک پلن انتخاب کنید"
   }

  val card=MaterialCardView(this).apply{
   radius=dp(24).toFloat()
   cardElevation=0f
   strokeWidth=dp(1)
   strokeColor=Color.parseColor(
    if(account.subscriptionActive)"#2A9C77" else "#315F99"
   )
   setCardBackgroundColor(Color.TRANSPARENT)
  }

  val box=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(18),dp(18),dp(18),dp(18))
   background=GradientDrawable(
    GradientDrawable.Orientation.TL_BR,
    intArrayOf(
     if(account.subscriptionActive){
      if(palette.dark)Color.parseColor("#123B34") else Color.parseColor("#E0F6EE")
     }else{
      palette.surfaceStrong
     },
     palette.surfaceStrong,
     palette.surface
    )
   ).apply{cornerRadius=dp(24).toFloat()}
  }

  val top=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER_VERTICAL
  }

  top.addView(TextView(this).apply{
   text=if(account.subscriptionActive)"فعال" else "تمدید"
   textSize=10f
   gravity=Gravity.CENTER
   setTextColor(palette.textPrimary)
   background=GradientDrawable().apply{
    shape=GradientDrawable.OVAL
    setColor(
     if(account.subscriptionActive){
      palette.success
     }else{
      palette.warning
     }
    )
   }
  },LinearLayout.LayoutParams(dp(48),dp(48)))

  val identity=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(12),0,dp(12),0)
  }
  identity.addView(TextView(this).apply{
   text=if(account.subscriptionActive){
    "اشتراک Premium فعال"
   }else{
    "حساب فعال • بدون اشتراک"
   }
   textSize=17f
   setTextColor(palette.textPrimary)
   setTypeface(typeface,Typeface.BOLD)
  })
  identity.addView(TextView(this).apply{
   text=account.email
   textSize=11.5f
   setTextColor(palette.textSecondary)
   setPadding(0,dp(4),0,0)
  })
  top.addView(identity,LinearLayout.LayoutParams(0,-2,1f))
  box.addView(top)

  val remaining=if(account.dataLimitBytes<=0L){
   "نامحدود"
  }else{
   bytes(
    (
     account.dataLimitBytes-account.usedTrafficBytes
    ).coerceAtLeast(0L)
   )
  }

  val metrics=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   setPadding(0,dp(16),0,dp(8))
  }
  metrics.addView(accountMetric(
   "حجم باقی‌مانده",
   if(account.subscriptionActive)remaining else "—"
  ),LinearLayout.LayoutParams(0,dp(78),1f))
  metrics.addView(accountMetric(
   "دستگاه مجاز",
   "${account.deviceLimit}"
  ),LinearLayout.LayoutParams(0,dp(78),1f).apply{
   marginStart=dp(8)
  })
  box.addView(metrics)

  box.addView(TextView(this).apply{
   text=if(account.subscriptionActive){
    val expireDisplay = account.expireFa
     ?: BlueVpnPersianDate.formatIso(account.expire)
     ?: "نامحدود"
    "اعتبار تا: $expireDisplay • تهران"
   }else{
    "پس از انتخاب پلن، کانفیگ‌ها خودکار به حساب اضافه می‌شوند."
   }
   textSize=12f
   gravity=Gravity.CENTER
   setTextColor(palette.textSecondary)
   setPadding(0,dp(5),0,dp(7))
  })

  box.addView(button("خروج از حساب","#6A2940").apply{
   setOnClickListener{
    BlueVpnAccountManager.logout(
     this@BlueVpnSubscriptionsActivity
    )
    setResult(RESULT_CANCELED)
    recreate()
   }
  },LinearLayout.LayoutParams(-1,dp(46)).apply{
   topMargin=dp(8)
  })

  card.addView(box)
  content.addView(card)
  if(!account.phoneVerified){phoneBindingCard()}
  loadPlans()
 }
 private fun phoneBindingCard(){
  val card=card()
  val box=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(dp(18),dp(18),dp(18),dp(18))}
  box.addView(TextView(this).apply{
   text="افزودن شماره تماس (اختیاری)"
   textSize=17f
   setTextColor(palette.textPrimary)
   setTypeface(typeface,Typeface.BOLD)
   gravity=Gravity.END
  })
  box.addView(TextView(this).apply{
   text="می‌توانید شماره خود را هم تأیید کنید تا علاوه بر ایمیل، ورود پیامکی نیز برای همین حساب در دسترس باشد."
   textSize=11.5f
   setTextColor(palette.textMuted)
   gravity=Gravity.END
   setPadding(0,dp(6),0,dp(12))
  })
  val phone=authField("شماره تماس؛ مثال 09123456789").apply{
   inputType=InputType.TYPE_CLASS_PHONE
   setText(draftBindingPhone.ifBlank{if(otpBinding)otpPhone else ""})
   remember(this){draftBindingPhone=it}
  }
  box.addView(phone,LinearLayout.LayoutParams(-1,dp(56)))
  if(otpBinding&&otpChallengeId.isNotBlank()){
   val code=authField("کد پیامکی").apply{inputType=InputType.TYPE_CLASS_NUMBER;setText(draftBindingCode);remember(this){draftBindingCode=it}}
   box.addView(code,LinearLayout.LayoutParams(-1,dp(56)).apply{topMargin=dp(8)})
   box.addView(button("تأیید و ثبت شماره","#18A873").apply{
    setOnClickListener{verifyOtp(phone.text.toString(),code.text.toString(),true)}
   },LinearLayout.LayoutParams(-1,dp(48)).apply{topMargin=dp(10)})
  }else{
   box.addView(button("ارسال کد تأیید","#1676FF").apply{
    setOnClickListener{requestOtp(phone.text.toString(),true)}
   },LinearLayout.LayoutParams(-1,dp(48)).apply{topMargin=dp(10)})
  }
  card.addView(box)
  content.addView(card,LinearLayout.LayoutParams(-1,-2).apply{topMargin=dp(12)})
 }
private fun loadPlans(){
 if(!BlueVpnAccountManager.hasSession(this)){
  render()
  return
 }

 lifecycleScope.launch(Dispatchers.IO){
  val r=BlueVpnAccountManager.plans(
   this@BlueVpnSubscriptionsActivity
  )

  withContext(Dispatchers.Main){
   r.onSuccess{arr->
    if(!BlueVpnAccountManager.hasSession(
      this@BlueVpnSubscriptionsActivity
     )){
     render()
     return@onSuccess
    }

    for(i in 0 until arr.length()){
     plan(arr.getJSONObject(i))
    }
   }.onFailure{
    if(!BlueVpnAccountManager.hasSession(
      this@BlueVpnSubscriptionsActivity
     )){
     render()
    }else{
     status.text=it.message?:"دریافت پلن ناموفق بود"
    }
   }
  }
 }
}
 private fun plan(p:JSONObject){
  val card=MaterialCardView(this).apply{
   radius=dp(22).toFloat()
   cardElevation=0f
   setCardBackgroundColor(palette.surface)
   strokeColor=palette.stroke
   strokeWidth=dp(1)
  }

  val box=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(17),dp(16),dp(17),dp(16))
  }

  val header=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER_VERTICAL
  }

  val titleBox=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
  }
  titleBox.addView(TextView(this).apply{
   text=p.optString("title")
   textSize=18f
   setTextColor(palette.textPrimary)
   setTypeface(typeface,Typeface.BOLD)
  })
  titleBox.addView(TextView(this).apply{
   text=p.optString("description")
   textSize=11.5f
   setTextColor(palette.textMuted)
   setPadding(0,dp(5),0,0)
  })
  header.addView(titleBox,LinearLayout.LayoutParams(0,-2,1f))
  header.addView(TextView(this).apply{
   text="PREMIUM"
   textSize=9.5f
   gravity=Gravity.CENTER
   setTextColor(Color.parseColor("#E7DDFF"))
   background=GradientDrawable(
    GradientDrawable.Orientation.TL_BR,
    intArrayOf(
     Color.parseColor("#7656D8"),
     Color.parseColor("#4D3B9D")
    )
   ).apply{cornerRadius=dp(12).toFloat()}
  },LinearLayout.LayoutParams(dp(76),dp(34)))
  box.addView(header)

  val days=p.optInt("duration_days")
  val gb=p.optInt("data_limit_gb")
  val features=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER
   setPadding(0,dp(14),0,0)
  }
  features.addView(planBadge(
   if(days==0)"زمان نامحدود" else "$days روز"
  ),LinearLayout.LayoutParams(0,dp(42),1f))
  features.addView(planBadge(
   if(gb==0)"حجم نامحدود" else "$gb گیگ"
  ),LinearLayout.LayoutParams(0,dp(42),1f).apply{
   marginStart=dp(6)
  })
  features.addView(planBadge(
   "${p.optInt("device_limit",1)} دستگاه"
  ),LinearLayout.LayoutParams(0,dp(42),1f).apply{
   marginStart=dp(6)
  })
  box.addView(features)

  val price=NumberFormat.getNumberInstance(
   Locale("fa")
  ).format(p.optInt("price_toman"))

  box.addView(button(
   "$price تومان • پرداخت امن با BluePay",
   "#1676FF"
  ).apply{
   textSize=13.5f
   setTypeface(typeface,Typeface.BOLD)
   setOnClickListener{
    buy(p.optInt("id"))
   }
  },LinearLayout.LayoutParams(-1,dp(52)).apply{
   topMargin=dp(14)
  })

  card.addView(box)
  content.addView(card,LinearLayout.LayoutParams(-1,-2).apply{
   topMargin=dp(11)
  })
 }
 private fun emailAuth(email:String,password:String,register:Boolean){
  if(busy)return
  draftEmail=email;draftPassword=password
  if(!email.contains("@")){Toast.makeText(this,"ایمیل معتبر وارد کنید",Toast.LENGTH_SHORT).show();return}
  if(password.length<8){Toast.makeText(this,"رمز عبور باید حداقل ۸ کاراکتر باشد",Toast.LENGTH_SHORT).show();return}
  busy=true;status.text=if(register)"در حال ساخت حساب..." else "در حال ورود..."
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.authenticateWithEmail(this@BlueVpnSubscriptionsActivity,email,password,register)
   withContext(Dispatchers.Main){
    busy=false
    result.onSuccess{draftPassword="";showAuthSuccess(if(register)"حساب شما ساخته شد" else "با موفقیت وارد شدید")}.onFailure{status.text=it.message?:if(register)"ثبت‌نام ناموفق بود" else "ورود ناموفق بود"}
   }
  }
 }
 private fun requestOtp(phone:String,bind:Boolean){
  if(busy)return
  val normalizedPhone=smsPhoneForApi(phone)
  if(bind)draftBindingPhone=normalizedPhone else draftPhone=normalizedPhone
  if(normalizedPhone.length!=11||!normalizedPhone.startsWith("09")){Toast.makeText(this,"شماره تماس معتبر وارد کنید",Toast.LENGTH_SHORT).show();return}
  busy=true;status.text="در حال ارسال کد تأیید..."
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.requestOtp(this@BlueVpnSubscriptionsActivity,normalizedPhone,bind)
   withContext(Dispatchers.Main){
    busy=false
    result.onSuccess{
     otpChallengeId=it.challengeId;otpPhone=it.phone;otpBinding=bind
     if(bind)draftBindingPhone=it.phone else draftPhone=it.phone
     status.text="کد تأیید ارسال شد"
     render()
    }.onFailure{status.text=it.message?:"ارسال کد ناموفق بود"}
   }
  }
 }
 private fun verifyOtp(phone:String,code:String,bind:Boolean){
  if(busy)return
  if(bind){draftBindingPhone=phone;draftBindingCode=code}else{draftPhone=phone;draftOtpCode=code}
  if(otpChallengeId.isBlank()){requestOtp(phone,bind);return}
  if(code.trim().length!=6){Toast.makeText(this,"کد تأیید باید ۶ رقمی باشد",Toast.LENGTH_SHORT).show();return}
  busy=true;status.text="در حال تأیید شماره تماس..."
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.verifyOtp(this@BlueVpnSubscriptionsActivity,phone,otpChallengeId,code,bind)
   withContext(Dispatchers.Main){
    busy=false
    result.onSuccess{
     otpChallengeId="";otpPhone="";otpBinding=false
     draftOtpCode="";draftBindingCode=""
     if(bind){setResult(RESULT_OK);render()}else{showAuthSuccess("شماره شما تأیید شد")}
    }.onFailure{status.text=it.message?:"کد تأیید معتبر نیست"}
   }
  }
 }
 private fun sync(force:Boolean){if(busy)return;busy=true;lifecycleScope.launch(Dispatchers.IO){val r=BlueVpnAccountManager.sync(this@BlueVpnSubscriptionsActivity,force);withContext(Dispatchers.Main){busy=false;r.onFailure{if(!BlueVpnAccountManager.hasSession(this@BlueVpnSubscriptionsActivity))render() else status.text=it.message?:"خطای همگام‌سازی"};if(currentFocus !is EditText)render()}}}
 private fun buy(planId:Int){
  if(busy)return
  BlueVpnAccountManager.clearPendingOrder(this)
  handler.removeCallbacks(poll)
  busy=true
  status.text="در حال ساخت فاکتور جدید..."
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.createOrder(this@BlueVpnSubscriptionsActivity,planId)
   withContext(Dispatchers.Main){
    busy=false
    result.onSuccess{o->
     val id=o.optString("id")
     val paymentUrl=o.optString("payment_url")
     if(id.isBlank()||paymentUrl.isBlank()){
      status.text="آدرس پرداخت معتبر دریافت نشد"
      return@onSuccess
     }
     BlueVpnAccountManager.setPendingOrder(this@BlueVpnSubscriptionsActivity,id)
     BlueVpnAccountManager.markCheckoutBrowserOpen(this@BlueVpnSubscriptionsActivity,id)
     status.text="مهلت پرداخت ۳۰ دقیقه است؛ پس از خروج، فاکتور ۵ دقیقه دیگر باز می‌ماند"
     try{
      startActivity(Intent(Intent.ACTION_VIEW,Uri.parse(paymentUrl)))
     }catch(error:Exception){
      BlueVpnAccountManager.clearCheckoutBrowserOrder(this@BlueVpnSubscriptionsActivity)
      closeCheckoutAfterReturn(id)
      status.text="مرورگر برای بازکردن صفحه پرداخت پیدا نشد"
     }
    }.onFailure{status.text=it.message?:"ساخت فاکتور ناموفق"}
   }
  }
 }
 private fun closeCheckoutAfterReturn(id:String,attempt:Int=0){
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.closeCheckout(this@BlueVpnSubscriptionsActivity,id)
   withContext(Dispatchers.Main){
    result.onSuccess{
     BlueVpnAccountManager.clearCheckoutBrowserOrder(this@BlueVpnSubscriptionsActivity)
     if(!isFinishing&&!isDestroyed){
      status.text="از صفحه پرداخت خارج شدید؛ این فاکتور تا ۵ دقیقه دیگر بسته می‌شود"
      checkOrder(id)
     }
    }.onFailure{error->
     if(BlueVpnAccountManager.isDeletedOrderError(error)){
      BlueVpnAccountManager.clearCheckoutBrowserOrder(this@BlueVpnSubscriptionsActivity)
      BlueVpnAccountManager.clearPendingOrder(this@BlueVpnSubscriptionsActivity)
      handler.removeCallbacks(poll)
      if(!isFinishing&&!isDestroyed){
       status.text="فاکتور باطل قبلی حذف شد؛ اکنون پرداخت جدید بسازید"
      }
     }else if(!isFinishing&&!isDestroyed&&attempt<3){
      handler.postDelayed(
       {closeCheckoutAfterReturn(id,attempt+1)},
       2000L*(attempt+1),
      )
     }else if(!isFinishing&&!isDestroyed){
      status.text="ثبت خروج از پرداخت موقتاً انجام نشد؛ در بازگشت بعدی دوباره تلاش می‌شود"
     }
    }
   }
  }
 }
 private fun checkOrder(id:String){lifecycleScope.launch(Dispatchers.IO){val r=BlueVpnAccountManager.order(this@BlueVpnSubscriptionsActivity,id);withContext(Dispatchers.Main){r.onSuccess{o->when(o.optString("status")){"activated"->{BlueVpnAccountManager.clearPendingOrder(this@BlueVpnSubscriptionsActivity);handler.removeCallbacks(poll);sync(true);Toast.makeText(this@BlueVpnSubscriptionsActivity,"اشتراک فعال شد",Toast.LENGTH_LONG).show()}"paid","paid_needs_sync","partial_needs_sync","activating"->status.text="پرداخت تأیید شد؛ فعال‌سازی در حال انجام است";"expired","expired_local","abandoned","superseded","canceled","cancelled","failed"->{BlueVpnAccountManager.clearPendingOrder(this@BlueVpnSubscriptionsActivity);handler.removeCallbacks(poll);status.text=if(o.optString("status")=="abandoned")"فاکتور قبلی بسته شد؛ اکنون دوباره پرداخت را بزنید" else "مهلت یا وضعیت فاکتور قبلی پایان یافت؛ پرداخت جدید بسازید"};else->status.text="در انتظار تأیید پرداخت..."}}.onFailure{error->
 if(BlueVpnAccountManager.isDeletedOrderError(error)){
  BlueVpnAccountManager.clearPendingOrder(this@BlueVpnSubscriptionsActivity)
  handler.removeCallbacks(poll)
  status.text="فاکتور باطل قبلی حذف شد؛ اکنون پرداخت جدید بسازید"
 }else{
  status.text=error.message?:"بررسی پرداخت ناموفق"
 }
}}}}
private fun accountMetric(
 title:String,
 value:String,
)=LinearLayout(this).apply{
 orientation=LinearLayout.VERTICAL
 gravity=Gravity.CENTER
 background=GradientDrawable().apply{
  cornerRadius=dp(15).toFloat()
  setColor(palette.surfaceSoft)
  setStroke(dp(1),palette.stroke)
 }
 addView(TextView(this@BlueVpnSubscriptionsActivity).apply{
  text=title
  textSize=10f
  gravity=Gravity.CENTER
  setTextColor(palette.textMuted)
 })
 addView(TextView(this@BlueVpnSubscriptionsActivity).apply{
  text=value
  textSize=15f
  gravity=Gravity.CENTER
  setTextColor(palette.textPrimary)
  setTypeface(typeface,Typeface.BOLD)
  setPadding(0,dp(4),0,0)
 })
}
private fun planBadge(value:String)=TextView(this).apply{
 text=value
 textSize=10.5f
 gravity=Gravity.CENTER
 setTextColor(palette.textPrimary)
 background=GradientDrawable().apply{
  cornerRadius=dp(13).toFloat()
  setColor(palette.surfaceStrong)
  setStroke(dp(1),palette.stroke)
 }
}
private fun authBg()=Color.parseColor("#030405")
private fun authAccent()=Color.parseColor("#F97316")
private fun authAccent2()=Color.parseColor("#EA580C")
private fun authMuted()=Color.parseColor("#9CA3AF")
private fun alphaColor(color:Int,alpha:Int)=Color.argb(alpha.coerceIn(0,255),Color.red(color),Color.green(color),Color.blue(color))
private fun authGlowDrawable(alpha:Int)=GradientDrawable(GradientDrawable.Orientation.TL_BR,intArrayOf(alphaColor(authAccent(),alpha),alphaColor(authAccent2(),alpha/2),Color.TRANSPARENT)).apply{
 shape=GradientDrawable.OVAL;gradientType=GradientDrawable.RADIAL_GRADIENT;gradientRadius=dp(150).toFloat()
}
private fun archiveSegment(label:String,active:Boolean)=TextView(this).apply{
 text=label;textSize=12.5f;gravity=Gravity.CENTER;setTypeface(typeface,if(active)Typeface.BOLD else Typeface.NORMAL);setTextColor(if(active)Color.WHITE else Color.parseColor("#A1A1AA"));isClickable=true;isFocusable=true
 background=if(active){GradientDrawable(GradientDrawable.Orientation.TL_BR,intArrayOf(Color.parseColor("#F97316"),Color.parseColor("#EA580C"),Color.parseColor("#C2410C"))).apply{cornerRadius=dp(12).toFloat()}}else{GradientDrawable().apply{cornerRadius=dp(12).toFloat();setColor(Color.TRANSPARENT)}}
}
private fun archiveMiniSegment(label:String,active:Boolean)=TextView(this).apply{
 text=label;textSize=11.5f;gravity=Gravity.CENTER;setTypeface(typeface,if(active)Typeface.BOLD else Typeface.NORMAL);setTextColor(if(active)Color.parseColor("#FED7AA") else Color.parseColor("#8E8E93"));isClickable=true;isFocusable=true
 background=GradientDrawable().apply{cornerRadius=dp(11).toFloat();setColor(if(active)Color.parseColor("#2A160C") else Color.TRANSPARENT);if(active)setStroke(dp(1),Color.parseColor("#6A3215"))}
}
private fun archiveTitle(textValue:String)=TextView(this).apply{
 text=textValue;textSize=23f;gravity=Gravity.CENTER;setTextColor(Color.WHITE);setTypeface(typeface,Typeface.BOLD);includeFontPadding=false
}
private fun archiveSubtitle(textValue:String)=TextView(this).apply{
 text=textValue;textSize=11.5f;gravity=Gravity.CENTER;setTextColor(authMuted());setLineSpacing(0f,1.18f);setPadding(dp(4),dp(9),dp(4),0)
}
private fun archiveLabel(textValue:String)=TextView(this).apply{
 text=textValue;textSize=11.5f;gravity=Gravity.END;setTextColor(Color.parseColor("#D4D4D8"));includeFontPadding=false
}
private fun archiveInput(hintText:String,inputTypeValue:Int,ltr:Boolean)=EditText(this).apply{
 hint=hintText;textSize=13.5f;isSingleLine=true;inputType=inputTypeValue;setTextColor(Color.WHITE);setHintTextColor(Color.parseColor("#71717A"));setPadding(dp(14),0,dp(14),0);includeFontPadding=false
 layoutDirection=if(ltr)View.LAYOUT_DIRECTION_LTR else View.LAYOUT_DIRECTION_RTL;gravity=Gravity.CENTER_VERTICAL or (if(ltr)Gravity.START else Gravity.END)
 background=archiveInputBackground(false)
 setOnFocusChangeListener{_,focused->background=archiveInputBackground(focused)}
}
private fun archiveInputBackground(focused:Boolean)=GradientDrawable().apply{
 cornerRadius=dp(14).toFloat();setColor(Color.parseColor("#0F1012"));setStroke(dp(if(focused)2 else 1),if(focused)authAccent() else Color.parseColor("#3F3F46"))
}
private fun archivePhoneField(initial:String):Pair<LinearLayout,EditText>{
 val shell=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER_VERTICAL;layoutDirection=View.LAYOUT_DIRECTION_LTR;background=archiveInputBackground(false)}
 val prefix=TextView(this).apply{text="+98";textSize=14f;gravity=Gravity.CENTER;setTextColor(Color.parseColor("#FB923C"));setTypeface(typeface,Typeface.BOLD);setPadding(dp(13),0,dp(13),0)}
 val input=EditText(this).apply{
  hint="9121234567";textSize=18f;letterSpacing=.045f;isSingleLine=true;inputType=InputType.TYPE_CLASS_PHONE;setTextColor(Color.WHITE);setHintTextColor(Color.parseColor("#5E5E64"));setPadding(dp(12),0,dp(12),0);background=ColorDrawable(Color.TRANSPARENT);layoutDirection=View.LAYOUT_DIRECTION_LTR;gravity=Gravity.CENTER_VERTICAL or Gravity.START;setText(initial)
  setOnFocusChangeListener{_,focused->shell.background=archiveInputBackground(focused)}
 }
 shell.addView(prefix,LinearLayout.LayoutParams(dp(58),-1))
 val divider=View(this).apply{setBackgroundColor(Color.parseColor("#2B2B2F"))}
 shell.addView(divider,LinearLayout.LayoutParams(dp(1),dp(28)))
 shell.addView(input,LinearLayout.LayoutParams(0,-1,1f))
 return Pair(shell,input)
}
private fun archivePrimary(label:String)=TextView(this).apply{
 text=label;textSize=13.5f;gravity=Gravity.CENTER;setTextColor(Color.WHITE);setTypeface(typeface,Typeface.BOLD);isClickable=true;isFocusable=true;elevation=dp(7).toFloat()
 background=GradientDrawable(GradientDrawable.Orientation.TL_BR,intArrayOf(Color.parseColor("#F97316"),Color.parseColor("#EA580C"),Color.parseColor("#C2410C"))).apply{cornerRadius=dp(13).toFloat()}
}
private fun archiveOtpRow(onDone:()->Unit):LinearLayout{
 val row=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER;layoutDirection=View.LAYOUT_DIRECTION_LTR}
 val fields=ArrayList<EditText>(6)
 for(i in 0 until 6){
  val field=EditText(this).apply{
   textSize=21f;gravity=Gravity.CENTER;setTextColor(Color.WHITE);setTypeface(typeface,Typeface.BOLD);inputType=InputType.TYPE_CLASS_NUMBER;isSingleLine=true;filters=arrayOf(InputFilter.LengthFilter(1));setPadding(0,0,0,0);background=archiveOtpBackground(false,false);includeFontPadding=false
   if(i<draftOtpCode.length)setText(draftOtpCode.substring(i,i+1))
  }
  fields.add(field)
  row.addView(field,LinearLayout.LayoutParams(0,dp(58),1f).apply{marginStart=dp(3);marginEnd=dp(3)})
 }
 fields.forEachIndexed{index,field->
  field.setOnFocusChangeListener{_,focused->field.background=archiveOtpBackground(focused,field.text.isNotBlank())}
  field.addTextChangedListener(object:TextWatcher{
   override fun beforeTextChanged(s:CharSequence?,start:Int,count:Int,after:Int){}
   override fun onTextChanged(s:CharSequence?,start:Int,before:Int,count:Int){
    field.background=archiveOtpBackground(field.hasFocus(),!s.isNullOrBlank())
    draftOtpCode=fields.joinToString(""){it.text?.toString().orEmpty()}
    if(!s.isNullOrEmpty()&&index<5)fields[index+1].requestFocus()
    if(draftOtpCode.length==6&&index==5)field.imeOptions=EditorInfo.IME_ACTION_DONE
   }
   override fun afterTextChanged(s:Editable?){}
  })
  field.setOnKeyListener{_,keyCode,event->
   if(keyCode==KeyEvent.KEYCODE_DEL&&event.action==KeyEvent.ACTION_DOWN&&field.text.isEmpty()&&index>0){fields[index-1].requestFocus();fields[index-1].setSelection(fields[index-1].text.length);true}else false
  }
  if(index==5)field.setOnEditorActionListener{_,actionId,_->if(actionId==EditorInfo.IME_ACTION_DONE){onDone();true}else false}
 }
 fields.firstOrNull{it.text.isEmpty()}?.requestFocus()
 return row
}
private fun archiveOtpBackground(focused:Boolean,filled:Boolean)=GradientDrawable().apply{
 cornerRadius=dp(12).toFloat();setColor(if(filled)Color.parseColor("#21140D") else Color.parseColor("#101114"));setStroke(dp(if(focused)2 else 1),when{focused->authAccent();filled->Color.parseColor("#A9561F");else->Color.parseColor("#3F3F46")})
}
private fun smsPhoneForApi(raw:String):String{
 val digits=raw.filter{it.isDigit()}
 return when{
  digits.startsWith("0098")&&digits.length>=14->"0"+digits.drop(4).take(10)
  digits.startsWith("98")&&digits.length>=12->"0"+digits.drop(2).take(10)
  digits.startsWith("0")&&digits.length>=11->digits.take(11)
  digits.length>=10->"0"+digits.takeLast(10)
  else->digits
 }
}
private fun smsPhoneNational(raw:String):String{
 val api=smsPhoneForApi(raw)
 return if(api.startsWith("0")&&api.length>1)api.drop(1) else api
}
private fun smsPhonePretty(raw:String):String{
 val api=smsPhoneForApi(raw)
 return if(api.length==11)"+98 ${api.substring(1,4)} ${api.substring(4,7)} ${api.substring(7)}" else raw
}
private fun showAuthSuccess(message:String){
 busy=false;setResult(RESULT_OK);status.text="";content.removeAllViews()
 val card=MaterialCardView(this).apply{radius=dp(28).toFloat();cardElevation=dp(12).toFloat();strokeWidth=dp(1);strokeColor=Color.parseColor("#234832");setCardBackgroundColor(Color.parseColor("#E6080A0C"))}
 val box=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;gravity=Gravity.CENTER;setPadding(dp(24),dp(36),dp(24),dp(36))}
 val check=TextView(this).apply{
  text="✓";textSize=40f;gravity=Gravity.CENTER;setTextColor(Color.parseColor("#052E16"));setTypeface(typeface,Typeface.BOLD);alpha=0f;scaleX=.55f;scaleY=.55f;elevation=dp(8).toFloat()
  background=GradientDrawable(GradientDrawable.Orientation.TL_BR,intArrayOf(Color.parseColor("#4ADE80"),Color.parseColor("#16A34A"))).apply{shape=GradientDrawable.OVAL}
 }
 box.addView(check,LinearLayout.LayoutParams(dp(82),dp(82)))
 box.addView(TextView(this).apply{text="ورود موفق!";textSize=23f;gravity=Gravity.CENTER;setTextColor(Color.parseColor("#4ADE80"));setTypeface(typeface,Typeface.BOLD);setPadding(0,dp(18),0,0)})
 box.addView(TextView(this).apply{text=message;textSize=11.5f;gravity=Gravity.CENTER;setTextColor(authMuted());setPadding(dp(5),dp(8),dp(5),0)})
 card.addView(box);content.addView(card,LinearLayout.LayoutParams(-1,-2).apply{topMargin=dp(30)})
 check.animate().alpha(1f).scaleX(1f).scaleY(1f).setDuration(500).start()
 handler.postDelayed({if(!isFinishing&&!isDestroyed)recreate()},760)
}
private fun authBadge(icon:String,label:String)=TextView(this).apply{
 text="$label • $icon"
 textSize=11.5f
 gravity=Gravity.CENTER
 setTextColor(palette.textPrimary)
 background=GradientDrawable().apply{
  cornerRadius=dp(13).toFloat()
  setColor(palette.surfaceStrong)
  setStroke(dp(1),Color.parseColor("#356BA8"))
 }
}
private fun authField(
 hintText:String,
)=EditText(this).apply{
 hint=hintText
 textSize=13f
 isSingleLine=true
 inputType=InputType.TYPE_CLASS_TEXT
 setTextColor(palette.textPrimary)
 setHintTextColor(palette.textMuted)
 setPadding(dp(15),0,dp(15),0)
 background=GradientDrawable().apply{
  cornerRadius=dp(15).toFloat()
  setColor(palette.surfaceSoft)
  setStroke(dp(1),palette.stroke)
 }
}
private fun card()=MaterialCardView(this).apply{radius=dp(22).toFloat();cardElevation=0f;setCardBackgroundColor(palette.surface);strokeColor=palette.stroke;strokeWidth=dp(1)}
 private fun button(t:String,color:Int)=MaterialButton(this).apply{text=t;textSize=13f;setTextColor(if(color==palette.accent)Color.WHITE else palette.textPrimary);backgroundTintList=ColorStateList.valueOf(color);cornerRadius=dp(16);isAllCaps=false;insetTop=0;insetBottom=0}
 private fun button(t:String,color:String)=button(t,Color.parseColor(color))
 private fun bytes(x:Long):String{val u=arrayOf("B","KB","MB","GB","TB");var v=x.toDouble();var i=0;while(v>=1024&&i<u.lastIndex){v/=1024;i++};return String.format(Locale.ROOT,"%.1f %s",v,u[i])}
 private fun dp(x:Int)=(x*resources.displayMetrics.density).toInt()
}
