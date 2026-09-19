<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>CMS</title>
    @vite(['resources/css/app.css', 'resources/js/cms/main.js'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    {{-- Đường dẫn gốc thật của CMS/API (có thể kèm thư mục con khi chạy trong XAMPP). CSP chặn script inline nên
         truyền qua thuộc tính data-* thay vì biến JavaScript. Chỉ lấy phần path, không có scheme/host. --}}
    <div id="app"
         data-cms-base="{{ parse_url(url('/cms'), PHP_URL_PATH) }}"
         data-api-base="{{ parse_url(url('/api'), PHP_URL_PATH) }}"></div>
    <noscript>This CMS requires JavaScript.</noscript>
</body>
</html>
