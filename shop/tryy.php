<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Counter Sales - Supplies Direct</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

   <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
        }
        /* Custom scrollbar for webkit browsers */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .sidebar {
            background-color: #ffffff;
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
        }
        .main-content {
            background-color: #f0f2f5;
        }
        .card {
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease-in-out;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .nav-item {
            transition: background-color 0.2s ease-in-out;
        }
        .nav-item.active, .nav-item:hover {
            background-color: #eef2ff;
            color: #4f46e5;
        }
        .nav-item.active svg, .nav-item:hover svg {
            color: #4f46e5;
        }
        .positive {
            color: #10b981;
        }
        .negative {
            color: #ef4444;
        }
    </style>
</head>
<body class="text-gray-800">

    <div class="flex h-screen">
        <!-- Sidebar -->
         <?php require_once 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content flex-1 p-8 overflow-y-auto">
            <!-- Header -->
            <header class="flex justify-between items-center mb-8">
                <div>
                    <?php
                        // PHP could be used to fetch the user's name dynamically
                        $userName = "Muhammad";
                    ?>
                    <h2 class="text-2xl font-bold text-gray-900">Welcome back, <?php echo $userName; ?></h2>
                    <p class="text-gray-500">Supplies Direct | Last login: Today, 7:15 AM</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-right">
                        <p id="current-date" class="font-medium text-gray-700"></p>
                    </div>
                    <button class="text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    </button>
                </div>
            </header>
    <link rel="stylesheet" href="style.css">
            <!-- Content Area -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center space-x-4">
                    <h3 class="text-3xl font-bold text-gray-800">Counter Sales</h3>
                </div>
                <button class="bg-gray-800 text-white p-2 rounded-lg hover:bg-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                </button>
            </div>
            
            <div class="flex space-x-6">
                <!-- Left Column -->
                <div class="w-1/3 space-y-6">
                    <div class="card p-6">
                        <div class="flex justify-between items-start">
                           <p class="text-gray-500">Opening Balance</p>
                           <button class="text-gray-400">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                           </button>
                        </div>
                        <?php $openingBalance = 200000; ?>
                        <p class="text-4xl font-bold mt-2">MWK<?php echo number_format($openingBalance, 0); ?></p>
                    </div>
                    <div class="bg-gray-200 p-4 rounded-lg">
                        <input type="text" placeholder="search ..." class="w-full bg-white p-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <div class="bg-white p-4 rounded-lg mt-4 shadow-sm">
                            <p class="font-semibold">Galvanized steel</p>
                            <div class="flex justify-between text-sm text-gray-500 mt-2">
                                <span>Opening</span>
                                <span class="font-bold text-gray-800">500 PCS</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="w-2/3">
                    <div class="grid grid-cols-2 gap-6">
                        <div class="card p-6">
                            <div class="flex justify-between items-start">
                                <p class="text-gray-500">Gross Transactions</p>
                                <button class="text-gray-500 hover:text-gray-800">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg>
                                </button>
                            </div>
                            <?php $grossTransactions = 15072; ?>
                            <p class="text-4xl font-bold mt-2">MWK<?php echo number_format($grossTransactions, 0); ?></p>
                            <div class="flex space-x-4 mt-2 text-md">
                                <span class="positive font-semibold">+20,070</span>
                                <span class="negative font-semibold">-5,002</span>
                            </div>
                        </div>
                        <div class="card p-6">
                            <p class="text-gray-500">Closing Balance</p>
                            <?php $closingBalance1 = 184930; ?>
                            <p class="text-4xl font-bold mt-2">MWK<?php echo number_format($closingBalance1, 0); ?></p>
                        </div>
                        <div class="card p-6">
                             <div class="flex justify-between items-start">
                                <p class="text-gray-500">Total Deducted</p>
                                <button class="text-gray-500 hover:text-gray-800">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg>
                                </button>
                            </div>
                            <p class="text-4xl font-bold mt-2">52</p>
                            <div class="flex space-x-4 mt-2 text-md">
                                <span class="positive font-semibold">+15</span>
                                <span class="negative font-semibold">-11</span>
                                <span class="negative font-semibold">-3</span>
                            </div>
                        </div>
                        <div class="card p-6">
                            <p class="text-gray-500">Closing Balance</p>
                             <p class="text-4xl font-bold mt-2">52</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="mt-8 bg-white rounded-lg shadow p-6">
                 <div class="grid grid-cols-5 gap-4 text-left text-sm font-semibold text-gray-500 border-b pb-4">
                    <div>Date</div>
                    <div>Transaction</div>
                    <div>Category</div>
                    <div>Amount</div>
                    <div>Customer</div>
                </div>
                <div class="h-48 overflow-y-auto">
                    <?php
                        // This would be a loop in a real PHP application
                        $transactions = [
                            ['date' => '2025-08-12', 'id' => 'TRN001', 'category' => 'Cement', 'amount' => 12500, 'customer' => 'John Doe'],
                            ['date' => '2025-08-12', 'id' => 'TRN002', 'category' => 'Paint', 'amount' => 7570, 'customer' => 'Jane Smith'],
                            ['date' => '2025-08-12', 'id' => 'TRN003', 'category' => 'Pipes', 'amount' => -5002, 'customer' => 'Refund'],
                            ['date' => '2025-08-11', 'id' => 'TRN004', 'category' => 'Tools', 'amount' => 8200, 'customer' => 'BuildCorp'],
                            ['date' => '2025-08-11', 'id' => 'TRN005', 'category' => 'Nails', 'amount' => 1800, 'customer' => 'Cash Sale'],
                        ];

                        foreach ($transactions as $transaction) {
                            echo '<div class="grid grid-cols-5 gap-4 items-center py-4 border-b text-gray-700 hover:bg-gray-50">';
                            echo '<div>' . date("M d, Y", strtotime($transaction['date'])) . '</div>';
                            echo '<div>' . $transaction['id'] . '</div>';
                            echo '<div>' . $transaction['category'] . '</div>';
                            echo '<div class="' . ($transaction['amount'] > 0 ? 'text-green-600' : 'text-red-600') . ' font-medium">MWK' . number_format($transaction['amount'], 2) . '</div>';
                            echo '<div>' . $transaction['customer'] . '</div>';
                            echo '</div>';
                        }
                    ?>
                     <!-- Placeholder for empty state -->
                    <div class="text-center py-10 text-gray-400 <?php if(!empty($transactions)) echo 'hidden'; ?>">
                        <p>No transactions for this period.</p>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        // JavaScript to set the current date
        document.addEventListener('DOMContentLoaded', function() {
            const dateElement = document.getElementById('current-date');
            const today = new Date();
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            dateElement.textContent = today.toLocaleDateString('en-US', options);
        });
    </script>

</body>
</html>
