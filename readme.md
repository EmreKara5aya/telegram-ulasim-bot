# Mersin Ulaşım Telegram Botu

Mersin Büyükşehir Belediyesi’nin ulaştırma servislerini kullanarak yetkilendirilmiş kullanıcılara rota önerisi, sefer saatleri ve canlı araç takibi sunan bir Telegram botu.

## Özellikler
- `/start` ile açılan ana menü: Rota planlama, kayıtlı yerler, hareket saatleri.
- **Kayıtlı yerler**: Konum paylaşarak yer kaydetme, listeleme ve silme; rota planlamada kalkış/varış olarak seçebilme.
- **Rota planlama**: Konum paylaşımı sonrası belediyenin `nasilgiderim` servisi ile hat önerisi, Google Maps bağlantıları ve en yakın araç ETA bilgisi.
- **Hareket saatleri**: `update_bus_lines.php` ile çekilen `state/bus_lines.json` üzerinden hat listesi ve seçilen hat için günlük tarifeler.
- **Canlı takip**: Önerilen hat için “Otobüsü takip et” butonu; 30 sn’de bir durak/hattın ETA ve durumunu güncelleyen işleyici.
- **Güvenli erişim**: `state/auth_users.json` içindeki Telegram ID’leri dışında bot hiçbir işlemi açmaz; sohbet içi kayıt komutu kapalıdır.

## Kod yapısı
- `telegram_bot.php`: Webhook girdisini işleyen ana bot. Menü akışı, rota çıkarımı, ETA hesaplaması ve takip döngüsü burada.
- `auth_storage.php`: Yetkili kullanıcıları okur/yazar (`state/auth_users.json`).
- `places_storage.php`: Sohbete özel yer kayıtlarını saklar (`state/places/places_<chatId>.json`).
- `update_bus_lines.php`: Belediye `hatbilgisi` servisini çağırıp `state/bus_lines.json` dosyasını günceller.
- `user_registry.php`: Yetkili kullanıcıları ekleyip silmek için basit yönetim paneli (form yoluyla POST).
- `state/`: Çalışma verileri (hat listesi, kullanıcılar, yerler, takip oturumları ve loglar).
- `latest_update.json`: Son alınan Telegram update içeriğini hata ayıklama amacıyla yazar.

## İşlem ve dosya/işlev eşlemesi
- **Yetkilendirme kontrolü**: `telegram_bot.php` içinde `authIsUserRegistered()` (kaynağı `auth_storage.php`) ile yapılır; kayıt yoksa yalnızca bilgilendirme menüsü gönderilir.
- **/start ve menü**: `telegram_bot.php` → `sendMainMenu()`; kullanıcı metinleri `/start`, “✨ Menüye Dön” ile tetiklenir.
- **Kayıtlı yerler**: `telegram_bot.php` → `sendPlacesMenu()`, `sendPlacesList()`, `sendPlacesDeleteMenu()`, `handleSavedPlaceSelection()`; veri okuma/yazma `places_storage.php` (`placesLoad`, `placesAdd`, `placesDelete`, `placesFind`).
- **Kullanıcı kayıt paneli**: `user_registry.php` formu; ekleme/güncelleme/silme işlemleri `auth_storage.php` (`authUpsertUser`, `authDeleteUser`, `authLoadUsers`).
- **Rota planlama**: `telegram_bot.php` → `summarizeRoutePlanning()`; belediye entegrasyonu `fetchRouteSuggestion()` (nasilgiderim), hat/ETA çözümleme `parseRouteAlternative()`, `fetchHatEtaForRoute()`, `findHatInfoForCode()`.
- **Google Maps linkleri**: `telegram_bot.php` → `buildGoogleMapsUrl()`; durak/iniş butonlarını `buildRouteButtons()` oluşturur.
- **Canlı takip**: `telegram_bot.php` → `registerTrackingRequest()` token üretir; `startTrackingFromRequest()` ve `runTrackingLoop()` 30 sn’lik güncellemeyi yapar; durum/ETA çekimi `fetchStopInfo()`, `extractMinutesFromHat()`, `extractVehicleStatus()`.
- **Hareket saatleri listesi**: `telegram_bot.php` → `loadBusLines()` `state/bus_lines.json` okur; arama/paginasyon `sendBusLinesMenuMessage()`, `sendBusLinesSearchMessage()`, `buildBusLinesPageKeyboard()`.
- **Hat tarifesi**: `telegram_bot.php` → `fetchBusSchedule()` (belediye `tarifeler` endpoint’i); gün seçimi `selectScheduleForToday()`, mesaj formatlama `sendBusScheduleMessages()`.
- **Hat listesi güncelleme**: `update_bus_lines.php` çalıştırılır; `state/bus_lines.json` dosyasını yeniler.
- **Hata ayıklama logları**: `telegram_bot.php` → `logUpdate()` (son Telegram update), `logRoutePlanningDebug()`, `logRouteCoordinateSample()`, `debugTrack()`; kayıtlar `state/` altına yazılır.

## Çalışma akışı
1. **Yetkilendirme**: Gelen update’in `chat.id` değeri `auth_users.json` içinde değilse yalnızca bilgilendirme mesajı ve `/start` menüsü gösterilir.
2. **Menü**: `/start` veya “✨ Menüye Dön” ile klavye gösterilir.
3. **Kayıtlı yerler**: Inline butonlarla yer ekleme (konum paylaş + isim), listeleme veya silme.
4. **Rota planlama**: Kalkış ve varış konumu paylaşılır ya da kayıtlı yer seçilir. `fetchRouteSuggestion()` belediye servisini çağırır; her çözüm için durak bilgisi, ETA ve Google Maps bağlantıları gönderilir.
5. **Canlı takip**: Rota sonucundaki “Otobüsü takip et” butonu `registerTrackingRequest()` ile token üretir. Bot aynı scripti async şekilde `track_worker` parametresiyle çağırır (veya CLI `track-worker` modu). 30 saniyelik döngüde durak bilgisi çekilip mesaj güncellenir; 20 dakikada otomatik sonlanır.
6. **Hareket saatleri**: Hat listesi paginasyon ve arama ile gösterilir. Hat seçildiğinde `fetchBusSchedule()` ilgili günün (hafta içi–cumartesi–pazar) tarifesini gönderir.

## Kurulum ve yapılandırma
- PHP 8.x ve `curl` eklentisi gerekir.
- Telegram bot token’ını ortam değişkeni olarak sağlayın:
  - Apache/Nginx: `SetEnv TELEGRAM_BOT_TOKEN <token>`
  - CLI test: `TELEGRAM_BOT_TOKEN=<token> php telegram_bot.php`
- Webhook hedefi olarak `telegram_bot.php` URL’sini ayarlayın.
- Bot varsayılan olarak `TELEGRAM_BOT_TOKEN` yoksa dosyada duran yedek token’ı kullanır; güvenlik için mutlaka kendi token’ınızı env üzerinden geçirin.

## Veri ve saklama dizinleri (`state/`)
- `auth_users.json`: Yetkili kullanıcı listesi.
- `bus_lines.json`: `update_bus_lines.php` çalışmasıyla güncel hat listesi.
- `places/places_<chatId>.json`: Kayıtlı yerler.
- `track/`: Aktif takip oturumları (mesaj ID, hat/durak, durum).
- `track_requests/`: Kısa ömürlü (60 sn) takip başlatma istekleri.
- `logs/route_debug.jsonl`, `logs/route_coordinates.jsonl`, `track_debug.log`: Rota ve takip hata ayıklama logları.

## Yönetim işlemleri
- **Kullanıcı ekleme/silme**: `user_registry.php` sayfasını açarak formdan ekleyin. Bot içinden kayıt komutu şu an devre dışı.
- **Hat listesini güncelleme**: `php update_bus_lines.php` çalıştırarak `state/bus_lines.json` dosyasını yenileyin (cron’a eklenebilir).
- **Takip işçisi**: Üretimde bot, HTTP üzerinden kendini tetikler; CLI testleri için `php telegram_bot.php track-worker <chatId> <token>` kullanılabilir.

## Notlar ve sınırlamalar
- Servisler `https://ulasim.mersin.bel.tr/**` uç noktalarına bağımlıdır; yanıt değişirse parse fonksiyonları güncellenmelidir.
- Takip işçisi en fazla 3 eşzamanlı oturuma izin verir; sınır aşılırsa en eski oturum düşürülür.
- Telegram mesaj sınırı için uzun yanıtlar `sendLongMessage()` ile parçalanır; 4096 karakter limitine karşı 3500 karakterlik bloklar kullanılır.
