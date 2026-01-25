<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopify Dashboard - City Farmers Malaga</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        .gradient-bg { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%); }
        .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
        .btn-primary:hover { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); }
    </style>
</head>
<body class="bg-slate-50 font-sans min-h-screen flex flex-col">
    <!-- Header -->
    <header class="gradient-bg text-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="bg-white/10 rounded-lg p-2.5">
                        <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold">City Farmers Malaga</h1>
                        <p class="text-white/70 text-sm">Shopify Integration Dashboard</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-sm bg-white/10 rounded-full px-4 py-2">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    </span>
                    <span class="text-white/90">Connected</span>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full">
        <!-- Actions Table -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
                <h2 class="text-lg font-semibold text-slate-900">Sync Actions</h2>
                <p class="text-sm text-slate-500 mt-1">Manage your Shopify synchronization tasks</p>
            </div>

            <table class="w-full">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left text-xs font-semibold text-slate-600 uppercase tracking-wider px-6 py-3">Action</th>
                        <th class="text-left text-xs font-semibold text-slate-600 uppercase tracking-wider px-6 py-3">Last Sync</th>
                        <th class="text-right text-xs font-semibold text-slate-600 uppercase tracking-wider px-6 py-3">Execute</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <!-- Sync Products Row -->
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-6 py-5">
                            <div class="flex items-center gap-3">
                                <div class="bg-blue-50 text-blue-600 rounded-lg p-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-sm font-medium text-slate-900">Sync Products</span>
                                    <p class="text-xs text-slate-500 mt-0.5">Import products from POS to Shopify</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5">
                            <span id="lastProductSync" class="text-sm text-slate-600">
                                @if($lastProductSync)
                                    {{ $lastProductSync->synced_at->format('M d, Y h:i a') }}
                                @else
                                    <span class="text-slate-400">Never synced</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-6 py-5 text-right">
                            <button
                                id="syncProductsBtn"
                                onclick="syncProducts()"
                                class="btn-primary text-white font-medium py-2 px-4 rounded-lg shadow-sm hover:shadow transition-all inline-flex items-center gap-2 text-sm"
                            >
                                <svg id="syncProductsIcon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                                </svg>
                                <span id="syncProductsBtnText">Sync Products</span>
                            </button>
                        </td>
                    </tr>

                    <!-- Download Mega Menu Row -->
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-6 py-5">
                            <div class="flex items-center gap-3">
                                <div class="bg-violet-50 text-violet-600 rounded-lg p-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/>
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-sm font-medium text-slate-900">Download Mega Menu</span>
                                    <p class="text-xs text-slate-500 mt-0.5">Generate and download Qikify menu JSON</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5">
                            <span id="lastMegaMenuSync" class="text-sm text-slate-600">
                                @if($lastMegaMenuSync)
                                    {{ $lastMegaMenuSync->synced_at->format('M d, Y h:i a') }}
                                @else
                                    <span class="text-slate-400">Never generated</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-6 py-5 text-right">
                            <button
                                id="megaMenuBtn"
                                onclick="downloadMegaMenu()"
                                class="bg-violet-500 hover:bg-violet-600 text-white font-medium py-2 px-4 rounded-lg shadow-sm hover:shadow transition-all inline-flex items-center gap-2 text-sm"
                            >
                                <svg id="megaMenuIcon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                                </svg>
                                <span id="megaMenuBtnText">Download</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Status Message -->
        <div id="message" class="mt-6"></div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-200 bg-white mt-auto">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <p class="text-slate-400 text-sm text-center">City Farmers Malaga &middot; Shopify Integration</p>
        </div>
    </footer>

    <script>
        function syncProducts() {
            const button = document.getElementById('syncProductsBtn');
            const btnText = document.getElementById('syncProductsBtnText');
            const icon = document.getElementById('syncProductsIcon');
            const messageDiv = document.getElementById('message');

            button.disabled = true;
            button.classList.add('opacity-60', 'pointer-events-none');
            btnText.textContent = 'Syncing...';
            icon.classList.add('animate-spin');

            messageDiv.innerHTML = `
                <div class="flex items-center gap-2 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg px-4 py-3">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-medium">Syncing products... This may take a few minutes.</span>
                </div>
            `;

            axios.get('/import')
                .then(response => {
                    icon.classList.remove('animate-spin');
                    button.disabled = false;
                    button.classList.remove('opacity-60', 'pointer-events-none');
                    btnText.textContent = 'Sync Products';

                    const data = response.data;
                    messageDiv.innerHTML = `
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-4 py-3">
                            <p class="font-medium text-sm">Sync completed successfully!</p>
                            <ul class="mt-2 text-sm space-y-1">
                                <li>Departments: ${data.departments.created} created, ${data.departments.skipped} skipped</li>
                                <li>Sub-Departments: ${data.sub_departments.created} created, ${data.sub_departments.skipped} skipped</li>
                                <li>Products: ${data.products.created} created, ${data.products.updated || 0} updated, ${data.products.skipped} skipped, ${data.products.deleted || 0} deleted</li>
                            </ul>
                            <p class="mt-2 text-sm text-emerald-600">Refreshing page...</p>
                        </div>
                    `;
                    setTimeout(() => window.location.reload(), 2000);
                })
                .catch(error => {
                    icon.classList.remove('animate-spin');
                    button.disabled = false;
                    button.classList.remove('opacity-60', 'pointer-events-none');
                    btnText.textContent = 'Sync Products';

                    messageDiv.innerHTML = `
                        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3">
                            <p class="font-medium text-sm">Sync failed</p>
                            <p class="mt-1 text-sm">${error.response?.data?.details || error.message}</p>
                        </div>
                    `;
                });
        }

        function downloadMegaMenu() {
            const btn = document.getElementById('megaMenuBtn');
            const btnText = document.getElementById('megaMenuBtnText');
            const icon = document.getElementById('megaMenuIcon');
            const messageDiv = document.getElementById('message');

            btn.disabled = true;
            btnText.textContent = 'Generating...';
            btn.classList.add('opacity-60', 'pointer-events-none');
            icon.classList.add('animate-spin');

            messageDiv.innerHTML = `
                <div class="flex items-center gap-2 bg-violet-50 border border-violet-200 text-violet-700 rounded-lg px-4 py-3">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-medium">Generating mega menu... This may take a minute.</span>
                </div>
            `;

            // Create a hidden link and trigger download
            const link = document.createElement('a');
            link.href = '/mega-menu/download';
            link.download = 'qikify_mega_menu.json';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Reset UI after download starts
            setTimeout(() => {
                btn.disabled = false;
                btnText.textContent = 'Download';
                btn.classList.remove('opacity-60', 'pointer-events-none');
                icon.classList.remove('animate-spin');
                messageDiv.innerHTML = `
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-4 py-3">
                        <p class="font-medium text-sm">Mega menu downloaded successfully!</p>
                        <p class="mt-1 text-sm text-emerald-600">Refreshing page...</p>
                    </div>
                `;
                setTimeout(() => window.location.reload(), 2000);
            }, 5000);
        }
    </script>
</body>
</html>
