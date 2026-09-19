<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Trang CMS gọi @vite() nên test không cần file build thật
beforeEach(fn () => $this->withoutVite());

test('/cms trả về trang SPA với thẻ gốc để Vue gắn vào', function () {
    $this->get('/cms')
        ->assertOk()
        ->assertSee('<div id="app"', false)
        ->assertSee('noindex', false);
});

test('mọi đường dẫn con dưới /cms đều trả về cùng trang SPA để Vue Router tự xử lý', function (string $path) {
    $this->get($path)->assertOk()->assertSee('<div id="app"', false);
})->with(['/cms/login', '/cms/dashboard', '/cms/users', '/cms/users/5/edit', '/cms/audit-logs', '/cms/khong/co/trang/nay']);

test('trang CMS không nhúng dữ liệu người dùng hay token nào', function () {
    $html = $this->get('/cms/users')->getContent();

    expect(strtolower($html))->not->toContain('token')->not->toContain('password')->not->toContain('bearer');
});

test('trang CMS gửi CSP chặn script ngoài và inline, chống nhúng iframe', function () {
    $response = $this->get('/cms');
    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("connect-src 'self'")
        ->not->toContain("script-src 'self' 'unsafe-inline'")
        ->not->toContain("'unsafe-eval'");

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin');
});

test('khi chạy Vite dev server (có file public/hot) thì không áp CSP để không làm hỏng trang dev', function () {
    $hot = public_path('hot');
    $existed = file_exists($hot);

    if (! $existed) {
        file_put_contents($hot, 'http://localhost:5173');
    }

    try {
        $response = $this->get('/cms');

        expect($response->headers->get('Content-Security-Policy'))->toBeNull();
        $response->assertHeader('X-Frame-Options', 'DENY');
    } finally {
        if (! $existed) {
            @unlink($hot);
        }
    }
});

test('header bảo mật của CMS không lan sang các route khác', function () {
    $this->get('/')->assertOk()->assertHeaderMissing('Content-Security-Policy');
});

test('/cms không che mất API: /api vẫn trả JSON', function () {
    $this->getJson('/api/dashboard')->assertStatus(401)->assertJson(['success' => false]);
    $this->postJson('/api/login', [])->assertStatus(422);
});

test('trang CMS truyền đường dẫn gốc của CMS và API cho frontend (chạy ở gốc domain)', function () {
    $this->get('/cms')
        ->assertOk()
        ->assertSee('data-cms-base="/cms"', false)
        ->assertSee('data-api-base="/api"', false);
});

test('chạy trong thư mục con của XAMPP thì đường dẫn gốc kèm tiền tố thư mục, chỉ có path, không có host', function () {
    // Render thẳng view: forceRootUrl cũng đổi URL của request test nên không thể dùng $this->get('/cms') ở đây
    URL::forceRootUrl('http://localhost/vibecoding-api/public');

    $html = view('cms')->render();

    expect($html)
        ->toContain('data-cms-base="/vibecoding-api/public/cms"')
        ->toContain('data-api-base="/vibecoding-api/public/api"')
        ->not->toContain('data-cms-base="http');
});
