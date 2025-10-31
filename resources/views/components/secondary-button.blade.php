<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white dark:bg-zinc-700 border border-gray-300 dark:border-zinc-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:text-gray-500 dark:hover:text-white focus:outline-none focus:border-blue-300 dark:focus:border-blue-500 focus:shadow-outline-blue active:text-gray-800 dark:active:text-white active:bg-gray-50 dark:active:bg-zinc-600 transition ease-in-out duration-150 cursor-pointer']) }}>
    {{ $slot }}
</button>
