---
paths:
  - 'app/Services/Auth/**,app/Notifications/**'
---

# Notifications

## OTP codes go out over WhatsApp only, through WhatsappChannel
The SMS stack (SmsSender, HttpSmsSender, LogSmsSender, services.sms, otp.default_channel) was removed. OtpService::send() stores channel=whatsapp and calls $driver->notify(new WhatsappOtpCode($code)). The API still accepts `channel` but ignores it, so older app builds don't get a 422.
WhatsappOtpCode is ShouldQueue + ShouldBeEncrypted, because the plain code must never be readable in the job payload. via() returns only WhatsappChannel: no database row and no FCM. It uses the Meta template `wigo_otp` with language `fr`, and the code goes in both the body {{1}} and the url button at index 0, because Meta rejects an AUTHENTICATION template missing either one.
WhatsappChannel reads WhatsappSettings when it sends (token encrypted, set on the Settings page) and builds the netflie client with app()->makeWith(WhatsAppCloudApi::class, ['config' => ...]). In tests, bind WhatsAppCloudApi to a client with a fake `client_handler` and never mock the client itself. In other tests, use Notification::fake() and read ->code from the sent notification.
