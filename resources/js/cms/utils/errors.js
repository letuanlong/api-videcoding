/**
 * Chuẩn hóa lỗi từ axios thành { status, message, errors } để mọi trang xử lý giống nhau.
 * errors: mỗi trường chỉ giữ thông báo đầu tiên (Laravel trả mảng thông báo cho từng trường).
 */
export function parseApiError(error) {
    if (!error?.response) {
        return {
            status: 0,
            message: 'Cannot reach the server. Please check your connection and try again.',
            errors: {},
        };
    }

    const { status, data } = error.response;

    const errors = Object.fromEntries(
        Object.entries(data?.errors ?? {}).map(([field, messages]) => [field, Array.isArray(messages) ? messages[0] : String(messages)]),
    );

    // Không hiển thị nguyên văn lỗi máy chủ: backend đã che chi tiết, và 429 cần câu dễ hiểu hơn
    let message = data?.message || 'Something went wrong. Please try again.';

    if (status === 429) {
        message = 'Too many attempts. Please wait a minute and try again.';
    } else if (status >= 500) {
        message = 'Server error. Please try again later.';
    }

    return { status, message, errors };
}
