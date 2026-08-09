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
import android.text.InputType
import android.text.TextWatcher
import android.text.TextUtils
import android.view.Gravity
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
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale
class BlueVpnSubscriptionsActivity:HelperBaseActivity(){
 private lateinit var content:LinearLayout;private lateinit var status:TextView;private lateinit var palette:BlueVpnPalette;private val handler=Handler(Looper.getMainLooper());private var busy=false;private var firstResume=true;private var otpChallengeId="";private var otpPhone="";private var otpBinding=false;private var authMode="sms";private var emailRegister=false;private var themeDarkAtCreate=true;private var renderedSessionState=false;private var draftPhone="";private var draftOtpCode="";private var draftEmail="";private var draftPassword="";private var draftBindingPhone="";private var draftBindingCode="";private var renderPosted=false;private var renderGeneration=0
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
 override fun onDestroy(){handler.removeCallbacksAndMessages(null);super.onDestroy()}
 private fun screen():View{
  palette=BlueVpnTheme.palette(this)
  val frame=FrameLayout(this).apply{setBackgroundColor(palette.background)}
  frame.addView(BlueVpnDynamicBackgroundView(this),FrameLayout.LayoutParams(-1,-1))
  val root=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(dp(18),dp(12),dp(18),dp(20));layoutDirection=View.LAYOUT_DIRECTION_RTL}
  val h=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER_VERTICAL}
  val back=button("بازگشت",palette.surfaceStrong).apply{setTextColor(palette.textPrimary);BlueVpnUiGuard.bind(this){finish()}}
  val title=TextView(this).apply{text="حساب BlueVPN";textSize=23f;setTextColor(palette.textPrimary);setTypeface(typeface,Typeface.BOLD);gravity=Gravity.END;includeFontPadding=false}
  h.addView(back,LinearLayout.LayoutParams(dp(92),dp(46)))
  h.addView(title,LinearLayout.LayoutParams(0,dp(50),1f))
  root.addView(h)
  status=TextView(this).apply{textSize=12f;setTextColor(palette.textMuted);gravity=Gravity.CENTER;setPadding(0,dp(6),0,dp(8));includeFontPadding=false;maxLines=3;ellipsize=TextUtils.TruncateAt.END}
  root.addView(status)
  content=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL}
  root.addView(ScrollView(this).apply{isFillViewport=true;overScrollMode=View.OVER_SCROLL_NEVER;addView(content)},LinearLayout.LayoutParams(-1,0,1f))
  frame.addView(root,FrameLayout.LayoutParams(-1,-1))
  return frame
 }
 private fun render(){
  if(!::content.isInitialized||isFinishing||isDestroyed)return
  renderGeneration++
  if(renderPosted)return
  renderPosted=true
  content.post{
   renderPosted=false
   if(isFinishing||isDestroyed||!::content.isInitialized)return@post
   val generation=renderGeneration
   val hasSession=BlueVpnAccountManager.hasSession(this)
   renderedSessionState=hasSession
   BlueVpnUiGuard.run(this,"render-account"){
    content.removeAllViews()
    if(hasSession)account(generation) else auth()
   }
  }
 }
 private fun remember(field:EditText,onValue:(String)->Unit){
  field.addTextChangedListener(object:TextWatcher{
   override fun beforeTextChanged(s:CharSequence?,start:Int,count:Int,after:Int){}
   override fun onTextChanged(s:CharSequence?,start:Int,before:Int,count:Int){onValue(s?.toString().orEmpty())}
   override fun afterTextChanged(s:Editable?){}
  })
 }
 private fun auth(){
  status.text=if(authMode=="sms")"ورود سریع با کد یک‌بارمصرف" else "ورود یا ثبت‌نام با ایمیل"

  val brand=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;gravity=Gravity.CENTER;setPadding(dp(12),dp(28),dp(12),dp(28))}
  brand.addView(TextView(this).apply{text="BlueVPN";textSize=35f;gravity=Gravity.CENTER;setTextColor(palette.accent);setTypeface(Typeface.create(Typeface.DEFAULT,Typeface.BOLD_ITALIC));includeFontPadding=false})
  val switchMark=LinearLayout(this).apply{
   gravity=Gravity.CENTER_VERTICAL
   background=GradientDrawable().apply{cornerRadius=dp(38).toFloat();setColor(palette.surfaceStrong);setStroke(dp(2),palette.accent)}
   setPadding(dp(8),dp(8),dp(8),dp(8))
  }
  switchMark.addView(TextView(this).apply{
   text="";background=GradientDrawable().apply{shape=GradientDrawable.OVAL;setColor(palette.accent)}
  },LinearLayout.LayoutParams(dp(58),dp(58)))
  brand.addView(switchMark,LinearLayout.LayoutParams(dp(150),dp(74)).apply{topMargin=dp(24)})
  brand.addView(TextView(this).apply{text="اتصال امن، ساده و سریع";textSize=12f;gravity=Gravity.CENTER;setTextColor(palette.textMuted);setPadding(0,dp(18),0,0)})
  content.addView(brand,LinearLayout.LayoutParams(-1,-2))

  val modeRow=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER;background=GradientDrawable().apply{cornerRadius=dp(18).toFloat();setColor(palette.surface);setStroke(dp(1),palette.stroke)};setPadding(dp(5),dp(5),dp(5),dp(5))}
  modeRow.addView(button("پیامک",if(authMode=="sms")palette.accent else Color.TRANSPARENT).apply{setTextColor(if(authMode=="sms")Color.WHITE else palette.textSecondary);BlueVpnUiGuard.bind(this){authMode="sms";emailRegister=false;render()}},LinearLayout.LayoutParams(0,dp(44),1f).apply{marginEnd=dp(4)})
  modeRow.addView(button("ایمیل",if(authMode=="email")palette.accent else Color.TRANSPARENT).apply{setTextColor(if(authMode=="email")Color.WHITE else palette.textSecondary);BlueVpnUiGuard.bind(this){authMode="email";otpChallengeId="";otpBinding=false;render()}},LinearLayout.LayoutParams(0,dp(44),1f).apply{marginStart=dp(4)})
  content.addView(modeRow,LinearLayout.LayoutParams(-1,dp(54)).apply{bottomMargin=dp(12)})

  val form=card()
  val box=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(dp(18),dp(20),dp(18),dp(20))}
  if(authMode=="sms"){
   box.addView(TextView(this).apply{text=if(otpChallengeId.isBlank())"ورود با شماره تماس" else "کد تأیید";textSize=19f;setTextColor(palette.textPrimary);setTypeface(typeface,Typeface.BOLD);gravity=Gravity.END})
   box.addView(TextView(this).apply{text=if(otpChallengeId.isBlank())"شماره موبایل خود را وارد کنید؛ اگر حساب نداشته باشید خودکار ساخته می‌شود." else "کد ارسال‌شده به ${otpPhone.ifBlank{"شماره شما"}} را وارد کنید.";textSize=11.5f;setTextColor(palette.textMuted);gravity=Gravity.END;setPadding(0,dp(6),0,dp(14))})
   val phone=authField("شماره تماس؛ مثال 09123456789").apply{inputType=InputType.TYPE_CLASS_PHONE;imeOptions=if(otpChallengeId.isBlank())EditorInfo.IME_ACTION_DONE else EditorInfo.IME_ACTION_NEXT;setText(draftPhone.ifBlank{otpPhone});remember(this){draftPhone=it}}
   box.addView(phone,LinearLayout.LayoutParams(-1,dp(58)))
   if(otpChallengeId.isBlank()){
    box.addView(button("ارسال کد تأیید",palette.accent).apply{textSize=14f;setTypeface(typeface,Typeface.BOLD);BlueVpnUiGuard.bind(this){requestOtp(phone.text.toString(),false)}},LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)})
   }else{
    val code=authField("کد پیامکی").apply{inputType=InputType.TYPE_CLASS_NUMBER;imeOptions=EditorInfo.IME_ACTION_DONE;setText(draftOtpCode);remember(this){draftOtpCode=it};setOnEditorActionListener{_,actionId,_->if(actionId==EditorInfo.IME_ACTION_DONE){verifyOtp(phone.text.toString(),text.toString(),false);true}else false}}
    box.addView(code,LinearLayout.LayoutParams(-1,dp(58)).apply{topMargin=dp(10)})
    box.addView(button("تأیید و ورود",palette.accent).apply{textSize=14f;setTypeface(typeface,Typeface.BOLD);BlueVpnUiGuard.bind(this){verifyOtp(phone.text.toString(),code.text.toString(),false)}},LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)})
    box.addView(button("ارسال دوباره",palette.surfaceStrong).apply{setTextColor(palette.textPrimary);strokeWidth=dp(1);strokeColor=ColorStateList.valueOf(palette.stroke);BlueVpnUiGuard.bind(this){otpChallengeId="";otpPhone=phone.text.toString();render()}},LinearLayout.LayoutParams(-1,dp(48)).apply{topMargin=dp(10)})
   }
  }else{
   box.addView(TextView(this).apply{text=if(emailRegister)"ساخت حساب" else "ورود با ایمیل";textSize=19f;setTextColor(palette.textPrimary);setTypeface(typeface,Typeface.BOLD);gravity=Gravity.END})
   box.addView(TextView(this).apply{text=if(emailRegister)"ایمیل و یک رمز حداقل ۸ کاراکتری تعیین کنید." else "ایمیل و رمز عبور حساب خود را وارد کنید.";textSize=11.5f;setTextColor(palette.textMuted);gravity=Gravity.END;setPadding(0,dp(6),0,dp(14))})
   val email=authField("ایمیل؛ example@email.com").apply{inputType=InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS;imeOptions=EditorInfo.IME_ACTION_NEXT;layoutDirection=View.LAYOUT_DIRECTION_LTR;gravity=Gravity.CENTER_VERTICAL or Gravity.START;setText(draftEmail);remember(this){draftEmail=it}}
   val password=authField("رمز عبور؛ حداقل ۸ کاراکتر").apply{inputType=InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD;imeOptions=EditorInfo.IME_ACTION_DONE;layoutDirection=View.LAYOUT_DIRECTION_LTR;gravity=Gravity.CENTER_VERTICAL or Gravity.START;setText(draftPassword);remember(this){draftPassword=it}}
   password.setOnEditorActionListener{_,actionId,_->if(actionId==EditorInfo.IME_ACTION_DONE){emailAuth(email.text.toString(),password.text.toString(),emailRegister);true}else false}
   box.addView(email,LinearLayout.LayoutParams(-1,dp(58)))
   box.addView(password,LinearLayout.LayoutParams(-1,dp(58)).apply{topMargin=dp(10)})
   box.addView(button(if(emailRegister)"ثبت‌نام و ورود" else "ورود",palette.accent).apply{textSize=14f;setTypeface(typeface,Typeface.BOLD);BlueVpnUiGuard.bind(this){emailAuth(email.text.toString(),password.text.toString(),emailRegister)}},LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)})
   box.addView(button(if(emailRegister)"حساب دارم" else "ساخت حساب جدید",palette.surfaceStrong).apply{setTextColor(palette.textPrimary);strokeWidth=dp(1);strokeColor=ColorStateList.valueOf(palette.stroke);BlueVpnUiGuard.bind(this){emailRegister=!emailRegister;render()}},LinearLayout.LayoutParams(-1,dp(48)).apply{topMargin=dp(10)})
  }
  box.addView(TextView(this).apply{text="نشست ورود روی همین دستگاه حفظ می‌شود و اشتراک در پس‌زمینه همگام خواهد شد.";textSize=10.5f;gravity=Gravity.CENTER;setTextColor(palette.textMuted);setPadding(0,dp(14),0,0)})
  form.addView(box);content.addView(form)
 }
 private fun account(generation:Int){
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
   BlueVpnUiGuard.bind(this){
    BlueVpnAccountManager.logout(
     this@BlueVpnSubscriptionsActivity
    )
    setResult(RESULT_CANCELED)
    render()
   }
  },LinearLayout.LayoutParams(-1,dp(46)).apply{
   topMargin=dp(8)
  })

  card.addView(box)
  content.addView(card)
  if(!account.phoneVerified){phoneBindingCard()}
  loadPlans(generation)
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
    BlueVpnUiGuard.bind(this){verifyOtp(phone.text.toString(),code.text.toString(),true)}
   },LinearLayout.LayoutParams(-1,dp(48)).apply{topMargin=dp(10)})
  }else{
   box.addView(button("ارسال کد تأیید","#1676FF").apply{
    BlueVpnUiGuard.bind(this){requestOtp(phone.text.toString(),true)}
   },LinearLayout.LayoutParams(-1,dp(48)).apply{topMargin=dp(10)})
  }
  card.addView(box)
  content.addView(card,LinearLayout.LayoutParams(-1,-2).apply{topMargin=dp(12)})
 }
private fun loadPlans(generation:Int){
 if(!BlueVpnAccountManager.hasSession(this)){
  render()
  return
 }

 lifecycleScope.launch(Dispatchers.IO){
  val r=BlueVpnAccountManager.plans(
   this@BlueVpnSubscriptionsActivity
  )

  withContext(Dispatchers.Main){
   if(isFinishing||isDestroyed||generation!=renderGeneration)return@withContext
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
   BlueVpnUiGuard.bind(this){
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
    if(isFinishing||isDestroyed)return@withContext
    busy=false
    result.onSuccess{draftPassword="";setResult(RESULT_OK);render()}.onFailure{status.text=it.message?:if(register)"ثبت‌نام ناموفق بود" else "ورود ناموفق بود"}
   }
  }
 }
 private fun requestOtp(phone:String,bind:Boolean){
  if(busy)return
  if(bind)draftBindingPhone=phone else draftPhone=phone
  if(phone.trim().length<10){Toast.makeText(this,"شماره تماس معتبر وارد کنید",Toast.LENGTH_SHORT).show();return}
  busy=true;status.text="در حال ارسال کد تأیید..."
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.requestOtp(this@BlueVpnSubscriptionsActivity,phone,bind)
   withContext(Dispatchers.Main){
    if(isFinishing||isDestroyed)return@withContext
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
  if(code.trim().length<4){Toast.makeText(this,"کد تأیید را کامل وارد کنید",Toast.LENGTH_SHORT).show();return}
  busy=true;status.text="در حال تأیید شماره تماس..."
  lifecycleScope.launch(Dispatchers.IO){
   val result=BlueVpnAccountManager.verifyOtp(this@BlueVpnSubscriptionsActivity,phone,otpChallengeId,code,bind)
   withContext(Dispatchers.Main){
    if(isFinishing||isDestroyed)return@withContext
    busy=false
    result.onSuccess{
     otpChallengeId="";otpPhone="";otpBinding=false
     draftOtpCode="";draftBindingCode=""
     setResult(RESULT_OK);render()
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
