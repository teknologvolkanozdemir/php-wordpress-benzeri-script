# Blog Ve1

Blog Ve1, PHP 8.1+ ve MySQL/MariaDB kullanan, sayfa oluşturma yerine kategori zorunlu blog yazılarına odaklanan küçük bir içerik yönetim sistemidir.

## Kurulum

1. PHP için `pdo_mysql`, `mbstring`, `fileinfo`, `zip` uzantılarını etkinleştirin. MySQL/MariaDB'de uygulama için bir veritabanı ve kullanıcısı oluşturun.
2. Web sunucunuzun belge kökünü `public/` dizini yapın ve istekleri `public/index.php` dosyasına yönlendirin. Geliştirme sunucusu:

   ```sh
   php -S 127.0.0.1:8000 -t public public/router.php
   ```

3. `http://127.0.0.1:8000/` adresindeki kurulum ekranına veritabanı bilgilerini ve en az 12 karakterli yönetici parolasını girin. Web sunucusu kullanıcısının proje kökündeki `storage/` dizinine yazma izni olmalıdır. Veritabanı tabloları ve `storage/config.php` ilk kurulumda oluşturulur.
4. Yönetim paneline `/yonetim` adresinden girin. Yönetim paneli yolunu sonradan Site Ayarları ekranından değiştirebilirsiniz.

`storage/` içinde veritabanı parolası, yüklenen logolar ve paketler bulunur; bu dizin belge kökü dışında kalmalıdır. `.gitignore` bu dizinin depoya eklenmesini önler. Üretimde HTTPS kullanın ve veritabanı kullanıcısına yalnızca bu uygulamanın veritabanında gerekli izinleri verin.

## Özellikler

- Taslak veya yayımlanmış yazı oluşturma/düzenleme; her yazı için zorunlu kategori.
- Kategori, yazı, iletişim formu ve anket araması yönetim panelinde; yayımlanan yazılarda site içi arama.
- Sınırsız form ve anket tanımlama, yayımlanan yazılara bağlama; form mesajlarını yönetim gelen kutusunda görüntüleme.
- Başlık, açıklama, anahtar kelimeler, SEO açıklaması, logo, SMTP bilgileri, herkese açık/üyelere özel görünürlük ve değiştirilebilir yönetim adresi.
- Tema ve eklenti ZIP paketlerini ekleme, etkinleştirme/devre dışı bırakma ve kaldırma.

WordPress temalarının `style.css` tema adı tanınır ve etkinleştirilen tema stil dosyası uygulanır. WordPress eklentilerinin `Plugin Name` başlığı tanınarak paketleri yönetilebilir. WordPress PHP tema şablonları ve eklenti kodları çalıştırılmaz; WordPress çekirdeğiyle tam uyum iddia edilmez. Bu sınır, yüklenen paketlerin sunucuda yetkisiz PHP kodu çalıştırmasını önler.
