# BlueVPN 3.0.6 — رفع خطای Kotlin ExecutorCompletionService

## خطای Build

```text
No type arguments expected for fun submit(Callable<Long?>): Future<Long?>
```

## علت

شیء `ExecutorCompletionService<Long?>` نوع خروجی را از قبل مشخص کرده است؛ بنابراین نوشتن `submit<Long?>` روی متد Java معتبر نیست.

## اصلاح

```kotlin
val completion = ExecutorCompletionService<Long?>(executor)
val futures = endpoints.map { endpoint ->
    completion.submit {
        requestThroughLocalXrayProxy(
            endpoint = endpoint,
            httpPort = httpPort,
        )
    }
}
```

علاوه بر اصلاح کد تعبیه‌شده، اسکریپت `prepare_android.py` پس از تولید فایل Kotlin بررسی می‌کند که عبارت نامعتبر `completion.submit<` وجود نداشته باشد.
