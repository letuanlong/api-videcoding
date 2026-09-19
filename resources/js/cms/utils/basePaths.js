const DEFAULT_CMS_BASE = '/cms';
const DEFAULT_API_BASE = '/api';

/**
 * Chỉ nhận đường dẫn nội bộ dạng "/thu-muc/con". Từ chối URL đầy đủ, "//host" và mọi giá trị lạ để dữ liệu
 * từ HTML không thể biến API/router trỏ sang origin khác. Bỏ dấu "/" ở cuối.
 */
function cleanBasePath(value, fallback) {
    const path = typeof value === 'string' ? value.trim() : '';

    if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\') || path.includes('://') || /[\s<>"']/.test(path)) {
        return fallback;
    }

    return path.replace(/\/+$/, '') || fallback;
}

/**
 * Laravel ghi đường dẫn gốc thật của CMS và API vào thẻ #app (data-cms-base, data-api-base).
 * Nhờ vậy cùng một bản build chạy được ở gốc domain (/cms, /api) lẫn trong thư mục con của XAMPP
 * (/vibecoding-api/public/cms, /vibecoding-api/public/api) mà không cần cấu hình lại.
 *
 * @returns {{ cms: string, api: string }} đường dẫn không có dấu "/" ở cuối
 */
export function readBasePaths(root = typeof document === 'undefined' ? null : document.getElementById('app')) {
    return {
        cms: cleanBasePath(root?.dataset?.cmsBase, DEFAULT_CMS_BASE),
        api: cleanBasePath(root?.dataset?.apiBase, DEFAULT_API_BASE),
    };
}
