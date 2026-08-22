# ประจวบคีรีขันธ์ Directory บน GitHub Pages

เว็บไซต์ไดเรกทอรีสถานที่ในจังหวัดประจวบคีรีขันธ์รุ่น static สำหรับเผยแพร่ผ่าน GitHub Pages โดยไม่ต้องติดตั้ง XAMPP, PHP หรือ MySQL เพื่อเปิดดูหน้าเว็บไซต์หลัก

## เปิดเว็บไซต์

หลังจาก GitHub Actions ทำงานสำเร็จ เว็บไซต์จะเปิดได้ที่:

<https://sixfivesolar-png.github.io/directory/>

## การเผยแพร่

ทุกครั้งที่มีการ push ไปยัง branch `main` workflow ใน `.github/workflows/pages.yml` จะนำไฟล์ static ที่จำเป็น ได้แก่ `index.html`, `404.html`, `.nojekyll` และโฟลเดอร์ `assets/` ไปเผยแพร่บน GitHub Pages

หน้าเว็บใช้ project base path `/directory/` เพื่อให้ asset และลิงก์ทำงานถูกต้องบน URL ของ repository นี้ การสร้าง `404.html` จากหน้าเดียวกันช่วยให้การเปิดเส้นทางภายในโดยตรงยังกลับเข้าสู่แอปได้

## ฟีเจอร์ที่ใช้งานได้แบบ static

ผู้เข้าชมสามารถดูหน้าแรก ข่าวสาร หมวดหมู่ ค้นหารายการ ดูข้อมูลสถานที่ แผนที่ และลิงก์นำทางได้จากข้อมูลที่ฝังอยู่ใน JavaScript bundle ฟีเจอร์ที่ต้องใช้ server เช่น การล็อกอิน รีวิว การส่งข้อมูล สมาชิก VIP คูปอง และสถิติ จะไม่ทำงานบน GitHub Pages เนื่องจาก GitHub Pages เป็น static hosting และไม่ประมวลผล PHP หรือเชื่อมต่อ MySQL

## พัฒนาในเครื่อง

สามารถเปิดโฟลเดอร์นี้ด้วย web server แบบ static ได้ เช่น:

```bash
python3 -m http.server 8000
```

จากนั้นเปิด <http://localhost:8000/> ในเบราว์เซอร์ ไม่จำเป็นต้องใช้ XAMPP สำหรับการดูหน้าเว็บ static

## โครงสร้างที่เผยแพร่

| รายการ | หน้าที่ |
| --- | --- |
| `index.html` | จุดเริ่มต้นของเว็บไซต์ |
| `404.html` | fallback สำหรับเส้นทางภายในของ single-page app |
| `assets/` | JavaScript, CSS และรูปภาพของเว็บไซต์ |
| `.github/workflows/pages.yml` | workflow สำหรับ deploy ไปยัง GitHub Pages |
| `prachuap-directory-xampp-latest.zip` | แพ็กเกจ XAMPP เดิมสำหรับดาวน์โหลดหรือเก็บเป็น archive |
