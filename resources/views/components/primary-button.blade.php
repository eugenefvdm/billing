<a {{ $attributes->merge(['class' => 'inline-flex items-center px-4 py-2 bg-gray-500 dark:bg-gray-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest shadow-sm hover:bg-gray-600 dark:hover:bg-gray-300 focus:outline-none focus:border-gray-600 dark:focus:border-gray-500 focus:shadow-outline-gray active:bg-gray-700 dark:active:bg-gray-500 transition ease-in-out duration-150 cursor-pointer']) }}>
    {{ $slot }}
</a>

