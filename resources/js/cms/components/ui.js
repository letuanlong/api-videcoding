// Class Tailwind dùng chung để giao diện đồng nhất giữa các form.
export const inputClass = (hasError = false) => [
    'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm',
    'placeholder:text-slate-400 focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500',
    hasError
        ? 'border-red-400 focus:border-red-500 focus:ring-red-200'
        : 'border-slate-300 focus:border-indigo-500 focus:ring-indigo-200',
];
