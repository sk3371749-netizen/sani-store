<?php
// Fix session issues on InfinityFree
if (session_status() === PHP_SESSION_ACTIVE) {
    // Check if user exists in database
    if (isset($_SESSION['user_id'])) {
        require_once 'config.php';
        $users = getUsers();
        $found = false;
        foreach ($users as $user) {
            if ($user['id'] == $_SESSION['user_id']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            // User not found, logout
            session_destroy();
            header('Location: index.php');
            exit;
        }
    }
}

// Force logout if requested
if (isset($_GET['force_logout'])) {
    session_start();
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$currentUser = null;
if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    if ($currentUser && $currentUser['blocked']) {
        session_start();
        session_destroy();
        header('Location: index.php?error=blocked');
        exit;
    }
}

// Get settings
$settings = getSettings();
$siteName = getSiteName();
$siteLogo = getSiteLogo();
$primaryColor = getPrimaryColor();

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] == 'add_to_cart' && isLoggedIn()) {
        $product_id = (int)$_POST['product_id'];
        $cart = getCart($_SESSION['user_id']);
        
        $found = false;
        foreach ($cart as &$item) {
            if ($item['product_id'] == $product_id) {
                $item['quantity']++;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $products = getProducts();
            foreach ($products as $p) {
                if ($p['id'] == $product_id) {
                    $cart[] = [
                        'product_id' => $p['id'],
                        'name' => $p['name'],
                        'price' => $p['price'],
                        'image' => isset($p['images']) && is_array($p['images']) ? $p['images'][0] : ($p['image'] ?? ''),
                        'quantity' => 1
                    ];
                    break;
                }
            }
        }
        
        saveCart($_SESSION['user_id'], $cart);
        echo json_encode(['success' => true, 'message' => 'Added to cart']);
        exit;
    }
    
    if ($_POST['action'] == 'remove_from_cart' && isLoggedIn()) {
        $product_id = (int)$_POST['product_id'];
        $cart = getCart($_SESSION['user_id']);
        $cart = array_filter($cart, function($item) use ($product_id) {
            return $item['product_id'] != $product_id;
        });
        saveCart($_SESSION['user_id'], array_values($cart));
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_POST['action'] == 'update_cart_qty' && isLoggedIn()) {
        $product_id = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        $cart = getCart($_SESSION['user_id']);
        
        foreach ($cart as &$item) {
            if ($item['product_id'] == $product_id) {
                if ($quantity <= 0) {
                    $cart = array_filter($cart, function($i) use ($product_id) {
                        return $i['product_id'] != $product_id;
                    });
                } else {
                    $item['quantity'] = $quantity;
                }
                break;
            }
        }
        
        saveCart($_SESSION['user_id'], array_values($cart));
        echo json_encode(['success' => true]);
        exit;
    }
}

// Get products and sort by sort_order
$products = getProducts();
usort($products, function($a, $b) {
    $orderA = isset($a['sort_order']) ? $a['sort_order'] : 0;
    $orderB = isset($b['sort_order']) ? $b['sort_order'] : 0;
    return $orderA - $orderB;
});

$categories = getCategories();
$banners = getBanners();

$cartItems = [];
$cartTotal = 0;
if (isLoggedIn()) {
    $cartItems = getCart($_SESSION['user_id']);
    foreach ($cartItems as $item) {
        $cartTotal += $item['price'] * $item['quantity'];
    }
}

$orders = [];
if (isLoggedIn()) {
    $allOrders = getOrders();
    foreach ($allOrders as $order) {
        if ($order['user_id'] == $_SESSION['user_id']) {
            $orders[] = $order;
        }
    }
    usort($orders, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

$coinValue = $settings['coin_value'] ?? 100;
$userCoins = $currentUser ? ($currentUser['coins'] ?? 0) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title><?php echo $siteName; ?> - Pakistan's Shopping Store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <style>
        * { font-family: 'Nunito', sans-serif; }
        body { background: #f0f4ff; }
        .page { display: none; }
        .page.active { display: block; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; z-index: 100; background: white; border-top: 1px solid #e5e7eb; }
        .bottom-nav-item.active-nav span { color: <?php echo $primaryColor; ?>; }
        .bottom-nav-item.active-nav i { color: <?php echo $primaryColor; ?>; }
        .category-scroll::-webkit-scrollbar { display: none; }
        .category-scroll { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes slideUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .slide-up { animation: slideUp 0.3s ease-out; }
        .fade-in { animation: fadeIn 0.3s ease-out; }
        .badge-count { min-width: 18px; height: 18px; font-size: 10px; }
        .toast { position: fixed; bottom: 140px; left: 50%; transform: translateX(-50%); background: #1e293b; color: white; padding: 12px 24px; border-radius: 99px; font-size: 14px; font-weight: 600; z-index: 300; white-space: nowrap; animation: slideUp 0.3s ease-out; display: none; }
        .section-header-main { background: linear-gradient(135deg, <?php echo $primaryColor; ?> 0%, #3b82f6 100%); }
        .product-img { width: 100%; aspect-ratio: 1; object-fit: cover; background: #f3f4f6; }
        .filter-btn.active { background: <?php echo $primaryColor; ?>; color: white; }
        .status-badge { padding: 3px 12px; border-radius: 99px; font-size: 11px; font-weight: 700; }
        .status-badge.placed { background: #dbeafe; color: #2563eb; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.processing { background: #dbeafe; color: #2563eb; }
        .status-badge.dispatched { background: #fef3c7; color: #d97706; }
        .status-badge.delivered { background: #d1fae5; color: #059669; }
        .status-badge.cancelled { background: #fee2e2; color: #dc2626; }
        .admin-toggle { position: fixed; bottom: 80px; right: 16px; z-index: 50; background: <?php echo $primaryColor; ?>; color: white; border: none; border-radius: 99px; padding: 12px 20px; font-weight: 700; font-size: 13px; box-shadow: 0 8px 24px rgba(37,99,235,0.4); cursor: pointer; display: flex; align-items: center; gap: 8px; text-decoration: none; }
        .admin-toggle:hover { background: #1d4ed8; }
        .banner-slider { position: relative; overflow: hidden; border-radius: 16px; margin: 0 16px; }
        .banner-slider .slides { display: flex; transition: transform 0.5s ease; }
        .banner-slider .slides img { min-width: 100%; max-height: 200px; object-fit: cover; }
        .banner-dots { display: flex; justify-content: center; gap: 8px; margin-top: 10px; }
        .banner-dots .dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; cursor: pointer; transition: all 0.3s; }
        .banner-dots .dot.active { background: <?php echo $primaryColor; ?>; width: 24px; border-radius: 4px; }
        .banner-btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.8); border: none; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; z-index: 10; }
        .banner-btn:hover { background: white; }
        .banner-btn.prev { left: 8px; }
        .banner-btn.next { right: 8px; }
        .coin-badge { display: inline-block; background: #fef3c7; color: #d97706; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; }
        .buy-coins-btn { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 4px 12px; border-radius: 99px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; }
        .buy-coins-btn:hover { transform: scale(1.05); }
        .coin-amount-btn { border: 2px solid #e2e8f0; border-radius: 12px; padding: 8px; text-align: center; cursor: pointer; transition: all 0.2s; font-weight: 700; font-size: 14px; }
        .coin-amount-btn:hover { border-color: #f59e0b; background: #fef3c7; }
        .coin-amount-btn.selected { border-color: #f59e0b; background: #fef3c7; }
        .file-upload-wrapper { position: relative; overflow: hidden; display: inline-block; width: 100%; }
        .file-upload-wrapper input[type=file] { position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        .image-preview { max-width: 200px; margin-top: 10px; border-radius: 12px; border: 2px solid #e2e8f0; display: none; }
        .image-preview img { width: 100%; height: auto; border-radius: 10px; }
        .coin-buy-btn { 
            background: linear-gradient(135deg, #f59e0b, #d97706); 
            color: white; 
            border: none; 
            border-radius: 50%; 
            width: 32px; 
            height: 32px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            cursor: pointer; 
            transition: all 0.3s;
            font-size: 12px;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        .coin-buy-btn:hover { 
            transform: scale(1.15);
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.5);
        }
    </style>
</head>
<body>

<!-- TOAST -->
<div id="toast" class="toast"></div>

<?php if (isAdmin()): ?>
<a href="admin.php" target="_blank" class="admin-toggle"><i class="fa-solid fa-gear"></i> Admin Panel</a>
<?php endif; ?>

<!-- ===== HEADER ===== -->
<header class="sticky top-0 z-50 bg-white shadow-sm px-4 py-3 flex items-center gap-3">
    <div class="flex items-center gap-1 flex-shrink-0 cursor-pointer" onclick="showPage('home')">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background: <?php echo $primaryColor; ?>;">
            <?php if (!empty($settings['site_logo_image'])): ?>
            <img src="<?php echo $settings['site_logo_image']; ?>" alt="Logo" class="w-full h-full object-cover rounded-lg" />
            <?php else: ?>
            <i class="fa-solid <?php echo $siteLogo; ?> text-white text-sm"></i>
            <?php endif; ?>
        </div>
        <span class="text-lg font-black" style="color: <?php echo $primaryColor; ?>;"><?php echo $siteName; ?></span>
    </div>
    <div class="flex-1 flex items-center bg-slate-100 rounded-full px-3 py-2 gap-2">
        <i class="fa-solid fa-magnifying-glass text-slate-400 text-sm"></i>
        <input type="text" id="searchInput" placeholder="Search products..." class="bg-transparent text-sm flex-1 text-slate-700 placeholder-slate-400" oninput="handleSearch(this.value)" />
    </div>
    <?php if ($currentUser): ?>
    <div class="hidden sm:flex items-center gap-2">
        <span class="text-xs font-bold text-amber-600 coin-badge"><i class="fa-solid fa-coins mr-1"></i><?php echo $currentUser['coins'] ?? 0; ?></span>
    </div>
    <?php endif; ?>
    <button class="relative flex-shrink-0" onclick="showPage('cart')">
        <i class="fa-solid fa-bag-shopping text-slate-600 text-xl"></i>
        <span id="cartBadge" class="badge-count absolute -top-1 -right-1 bg-red-500 text-white rounded-full flex items-center justify-center font-bold hidden"><?php echo count($cartItems); ?></span>
    </button>
</header>

<!-- ===== MAIN CONTENT ===== -->
<div id="mainContent" class="pb-20">

    <!-- PAGE: HOME -->
    <div id="page-home" class="page active fade-in">
        <div class="section-header-main px-4 pt-4 pb-8 text-white">
            <p class="text-xs font-semibold opacity-80 uppercase tracking-widest">Welcome to</p>
            <h2 class="text-2xl font-black"><?php echo $siteName; ?> 🛒</h2>
            <p class="text-sm opacity-80 mt-1">Pakistan's favorite online shopping store</p>
            <?php if ($currentUser): ?>
            <div class="flex flex-wrap items-center gap-3 mt-2">
                <p class="text-sm font-bold coin-badge bg-amber-400/30 text-white"><i class="fa-solid fa-coins mr-1"></i><?php echo $currentUser['coins'] ?? 0; ?> <?php echo COIN_NAME; ?></p>
                <button onclick="showPage('buy-coins')" class="buy-coins-btn text-xs"><i class="fa-solid fa-plus mr-1"></i>Buy Coins</button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Banners Slider -->
        <?php 
        $activeBanners = array_filter($banners, function($b) { return isset($b['active']) && $b['active'] === true; });
        if (!empty($activeBanners)): 
        ?>
        <div class="banner-slider mt-2">
            <div class="slides" id="bannerSlides">
                <?php foreach ($activeBanners as $b): ?>
                <a href="<?php echo $b['link'] ?? '#'; ?>" target="_blank">
                    <img src="<?php echo $b['image']; ?>" alt="<?php echo $b['title']; ?>" loading="lazy" />
                </a>
                <?php endforeach; ?>
            </div>
            <?php if (count($activeBanners) > 1): ?>
            <button class="banner-btn prev" onclick="changeBanner(-1)"><i class="fa-solid fa-chevron-left text-sm"></i></button>
            <button class="banner-btn next" onclick="changeBanner(1)"><i class="fa-solid fa-chevron-right text-sm"></i></button>
            <div class="banner-dots" id="bannerDots">
                <?php for ($i = 0; $i < count($activeBanners); $i++): ?>
                <div class="dot <?php echo $i === 0 ? 'active' : ''; ?>" onclick="goToBanner(<?php echo $i; ?>)"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="bg-white mx-4 -mt-4 rounded-2xl shadow-lg p-4 mt-4">
            <h3 class="text-sm font-bold text-slate-700 mb-3">Shop by Category</h3>
            <div class="category-scroll flex gap-4 overflow-x-auto pb-1" id="categoryRow">
                <?php foreach ($categories as $cat): ?>
                <div class="flex flex-col items-center gap-2 cursor-pointer flex-shrink-0" onclick="filterByCategory('<?php echo $cat['name']; ?>')">
                    <div class="w-14 h-14 <?php echo $cat['color']; ?> rounded-full flex items-center justify-center shadow-sm hover:scale-110 transition-transform">
                        <i class="fa-solid <?php echo $cat['icon']; ?> text-lg"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-600 text-center w-14"><?php echo $cat['name']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mx-4 mt-4 bg-gradient-to-r from-green-400 to-emerald-500 rounded-2xl p-4 flex justify-between items-center">
            <div>
                <p class="text-white font-black text-lg">🇵🇰 Mega Sale!</p>
                <p class="text-white text-xs opacity-90">Free Delivery on orders above Rs. 2000</p>
            </div>
            <button onclick="showPage('products')" class="bg-white text-emerald-600 font-bold text-xs px-4 py-2 rounded-full">Shop Now</button>
        </div>

        <div class="px-4 mt-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-base font-black text-slate-800">Featured Products</h3>
                <button onclick="showPage('products')" class="text-blue-600 text-xs font-bold">See All →</button>
            </div>
            <div class="grid grid-cols-2 gap-3" id="homeProductGrid">
                <?php 
                $featured = array_slice($products, 0, 6);
                foreach ($featured as $p): 
                ?>
                <div class="bg-white rounded-2xl shadow-md overflow-hidden cursor-pointer hover:shadow-lg transition-shadow" onclick="window.location.href='product.php?id=<?php echo $p['id']; ?>'">
                    <div class="relative">
                        <img src="<?php echo isset($p['images']) && is_array($p['images']) ? $p['images'][0] : ($p['image'] ?? 'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=400&q=80'); ?>" alt="<?php echo $p['name']; ?>" class="product-img" loading="lazy" />
                        <?php if ($p['stock'] < 10): ?>
                        <span class="absolute top-2 left-2 bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">Only <?php echo $p['stock']; ?> left</span>
                        <?php endif; ?>
                        <span class="absolute top-2 right-2 bg-white bg-opacity-90 text-xs font-bold px-2 py-0.5 rounded-full text-slate-600 shadow"><?php echo $p['category']; ?></span>
                    </div>
                    <div class="p-3">
                        <p class="text-slate-800 font-bold text-sm leading-tight line-clamp-2 mb-1"><?php echo $p['name']; ?></p>
                        <div class="flex items-center justify-between">
                            <span class="text-blue-600 font-black text-lg"><?php echo formatPrice($p['price']); ?></span>
                            <div class="flex items-center gap-1">
                                <?php if ($currentUser && ($currentUser['coins'] ?? 0) > 0): ?>
                                <button onclick="event.stopPropagation(); buyWithCoins(<?php echo $p['id']; ?>)" 
                                    class="coin-buy-btn"
                                    title="Buy with Coins">
                                    <i class="fa-solid fa-coins"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="event.stopPropagation(); addToCart(<?php echo $p['id']; ?>)" 
                                    class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center hover:scale-110 transition-transform shadow">
                                    <i class="fa-solid fa-plus text-xs"></i>
                                </button>
                            </div>
                        </div>
                        <?php if ($currentUser && ($currentUser['coins'] ?? 0) > 0): ?>
                        <div class="mt-1">
                            <span class="text-[10px] text-amber-600 font-semibold">
                                <i class="fa-solid fa-coins mr-1"></i><?php echo ceil($p['price'] / $coinValue); ?> <?php echo COIN_NAME; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- PAGE: PRODUCTS -->
    <div id="page-products" class="page fade-in">
        <div class="px-4 pt-4 pb-2">
            <h2 class="text-lg font-black text-slate-800 mb-3">All Products</h2>
            <div class="category-scroll flex gap-2 overflow-x-auto pb-2" id="filterRow">
                <button onclick="renderProducts('All')" class="filter-btn active flex-shrink-0 px-4 py-2 rounded-full text-xs font-bold border-2 transition bg-blue-600 text-white border-blue-600">All</button>
                <?php foreach ($categories as $cat): ?>
                <button onclick="renderProducts('<?php echo $cat['name']; ?>')" class="filter-btn flex-shrink-0 px-4 py-2 rounded-full text-xs font-bold border-2 transition bg-white text-slate-600 border-slate-200 hover:border-blue-300">
                    <?php echo $cat['name']; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="px-4 mt-2">
            <div class="grid grid-cols-2 gap-3" id="productGrid">
                <?php foreach ($products as $p): ?>
                <div class="bg-white rounded-2xl shadow-md overflow-hidden cursor-pointer hover:shadow-lg transition-shadow" onclick="window.location.href='product.php?id=<?php echo $p['id']; ?>'">
                    <div class="relative">
                        <img src="<?php echo isset($p['images']) && is_array($p['images']) ? $p['images'][0] : ($p['image'] ?? 'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=400&q=80'); ?>" alt="<?php echo $p['name']; ?>" class="product-img" loading="lazy" />
                        <?php if ($p['stock'] < 10): ?>
                        <span class="absolute top-2 left-2 bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">Only <?php echo $p['stock']; ?> left</span>
                        <?php endif; ?>
                        <span class="absolute top-2 right-2 bg-white bg-opacity-90 text-xs font-bold px-2 py-0.5 rounded-full text-slate-600 shadow"><?php echo $p['category']; ?></span>
                    </div>
                    <div class="p-3">
                        <p class="text-slate-800 font-bold text-sm leading-tight line-clamp-2 mb-1"><?php echo $p['name']; ?></p>
                        <div class="flex items-center justify-between">
                            <span class="text-blue-600 font-black text-lg"><?php echo formatPrice($p['price']); ?></span>
                            <div class="flex items-center gap-1">
                                <?php if ($currentUser && ($currentUser['coins'] ?? 0) > 0): ?>
                                <button onclick="event.stopPropagation(); buyWithCoins(<?php echo $p['id']; ?>)" 
                                    class="coin-buy-btn"
                                    title="Buy with Coins">
                                    <i class="fa-solid fa-coins"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="event.stopPropagation(); addToCart(<?php echo $p['id']; ?>)" 
                                    class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center hover:scale-110 transition-transform shadow">
                                    <i class="fa-solid fa-plus text-xs"></i>
                                </button>
                            </div>
                        </div>
                        <?php if ($currentUser && ($currentUser['coins'] ?? 0) > 0): ?>
                        <div class="mt-1">
                            <span class="text-[10px] text-amber-600 font-semibold">
                                <i class="fa-solid fa-coins mr-1"></i><?php echo ceil($p['price'] / $coinValue); ?> <?php echo COIN_NAME; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="noResults" class="hidden text-center py-12">
                <i class="fa-solid fa-box-open text-4xl text-slate-300"></i>
                <p class="text-slate-500 font-semibold mt-3">No products found</p>
            </div>
        </div>
    </div>

    <!-- PAGE: CART -->
    <div id="page-cart" class="page fade-in">
        <div class="px-4 pt-4">
            <h2 class="text-lg font-black text-slate-800 mb-4">My Cart 🛒</h2>
            <div id="cartItems">
                <?php if (empty($cartItems)): ?>
                <div id="emptyCart" class="text-center py-16">
                    <i class="fa-solid fa-cart-shopping text-6xl text-slate-200"></i>
                    <p class="text-slate-500 font-bold text-lg mt-4">Your cart is empty</p>
                    <p class="text-slate-400 text-sm mt-1">Add items to get started</p>
                    <button onclick="showPage('products')" class="mt-6 bg-blue-600 text-white px-8 py-3 rounded-full font-bold text-sm">Browse Products</button>
                </div>
                <?php else: ?>
                <div id="cartSummary" class="bg-white rounded-2xl shadow-md p-4 mt-4 mb-4">
                    <?php foreach ($cartItems as $item): ?>
                    <div class="flex gap-3 mb-3 pb-3 border-b border-slate-100">
                        <img src="<?php echo $item['image'] ?? 'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=200&q=80'; ?>" alt="<?php echo $item['name']; ?>" class="w-20 h-20 rounded-xl object-cover flex-shrink-0" />
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-slate-800 text-sm leading-tight truncate"><?php echo $item['name']; ?></p>
                            <p class="text-blue-600 font-black text-base mt-1"><?php echo formatPrice($item['price'] * $item['quantity']); ?></p>
                            <p class="text-slate-400 text-xs"><?php echo formatPrice($item['price']); ?> each</p>
                            <div class="flex items-center justify-between mt-2">
                                <div class="flex items-center gap-2 bg-slate-100 rounded-full px-1 py-0.5">
                                    <button onclick="updateCartQty(<?php echo $item['product_id']; ?>, -1)" class="w-6 h-6 bg-white rounded-full flex items-center justify-center shadow-sm font-black text-slate-600 text-sm hover:bg-slate-50">-</button>
                                    <span class="font-bold text-slate-700 text-sm w-5 text-center"><?php echo $item['quantity']; ?></span>
                                    <button onclick="updateCartQty(<?php echo $item['product_id']; ?>, 1)" class="w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center shadow-sm font-black text-white text-sm hover:bg-blue-700">+</button>
                                </div>
                                <button onclick="removeFromCart(<?php echo $item['product_id']; ?>)" class="text-red-400 hover:text-red-600 transition">
                                    <i class="fa-solid fa-trash text-sm"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Coin Information -->
                    <div class="mt-4 p-3 bg-amber-50 rounded-xl border border-amber-200">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-slate-700"><i class="fa-solid fa-coins text-amber-500 mr-1"></i>Your Coins</span>
                            <span class="font-bold text-amber-600"><?php echo $currentUser ? ($currentUser['coins'] ?? 0) : 0; ?> <?php echo COIN_NAME; ?></span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">1 <?php echo COIN_NAME; ?> = <?php echo formatPrice($coinValue); ?></p>
                        <?php 
                        $totalCoinsNeeded = ceil($cartTotal / $coinValue);
                        if ($currentUser && ($currentUser['coins'] ?? 0) >= $totalCoinsNeeded && $cartTotal > 0): 
                        ?>
                        <p class="text-xs text-green-600 mt-1">✅ You have enough coins to pay for this order!</p>
                        <button onclick="payCartWithCoins()" class="mt-2 w-full bg-amber-500 text-white py-2 rounded-xl font-bold text-sm hover:bg-amber-600 transition">
                            <i class="fa-solid fa-coins mr-1"></i> Pay with Coins (<?php echo $totalCoinsNeeded; ?> <?php echo COIN_NAME; ?>)
                        </button>
                        <?php elseif ($currentUser && ($currentUser['coins'] ?? 0) > 0 && $cartTotal > 0): ?>
                        <p class="text-xs text-amber-600 mt-1">⚠️ You need <?php echo $totalCoinsNeeded; ?> <?php echo COIN_NAME; ?> coins. You have <?php echo $currentUser['coins'] ?? 0; ?></p>
                        <button onclick="showPage('buy-coins')" class="mt-2 w-full bg-amber-500 text-white py-2 rounded-xl font-bold text-sm hover:bg-amber-600 transition">
                            <i class="fa-solid fa-plus mr-1"></i> Buy More Coins
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex justify-between items-center text-sm mb-2 mt-3">
                        <span class="text-slate-500">Subtotal</span>
                        <span class="font-bold" id="cartSubtotal"><?php echo formatPrice($cartTotal); ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm mb-2">
                        <span class="text-slate-500">Delivery</span>
                        <span class="font-bold text-green-600">FREE</span>
                    </div>
                    <div class="border-t pt-3 flex justify-between items-center">
                        <span class="font-black text-slate-800">Total</span>
                        <span class="font-black text-blue-600 text-xl" id="cartTotal"><?php echo formatPrice($cartTotal); ?></span>
                    </div>
                    
                    <a href="checkout.php" class="w-full mt-4 bg-blue-600 text-white py-3 rounded-full font-bold text-sm flex items-center justify-center gap-2 hover:bg-blue-700 transition text-center">
                        <i class="fa-solid fa-lock text-xs"></i> Proceed to Checkout
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- PAGE: ORDERS -->
    <div id="page-orders" class="page fade-in">
        <div class="px-4 pt-4">
            <h2 class="text-lg font-black text-slate-800 mb-4">My Orders 📦</h2>
            <div id="ordersList">
                <?php if (empty($orders)): ?>
                <div id="emptyOrders" class="text-center py-16">
                    <i class="fa-solid fa-box-open text-6xl text-slate-200"></i>
                    <p class="text-slate-500 font-bold text-lg mt-4">No orders yet</p>
                    <button onclick="showPage('products')" class="mt-4 bg-blue-600 text-white px-8 py-3 rounded-full font-bold text-sm">Start Shopping</button>
                </div>
                <?php else: ?>
                <?php foreach ($orders as $order): ?>
                <div class="bg-white rounded-2xl shadow-md p-4 mb-4">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <p class="font-black text-slate-800 text-sm"><?php echo $order['order_id']; ?></p>
                            <p class="text-slate-400 text-xs mt-0.5"><?php echo $order['order_date']; ?></p>
                            <p class="text-slate-400 text-xs">Payment: <?php echo $order['payment_method']; ?></p>
                            <p class="text-slate-400 text-xs">Status: <?php echo $order['payment_status']; ?></p>
                            <?php if ($order['payment_method'] == 'Coins'): ?>
                            <span class="coin-badge text-[10px]"><i class="fa-solid fa-coins mr-1"></i>Coin Purchase</span>
                            <?php endif; ?>
                        </div>
                        <?php 
                        $statusClass = 'placed';
                        if (strpos($order['status'], 'Pending') !== false) {
                            $statusClass = 'pending';
                        } elseif ($order['status'] == 'Processing') {
                            $statusClass = 'processing';
                        } elseif ($order['status'] == 'Dispatched') {
                            $statusClass = 'dispatched';
                        } elseif ($order['status'] == 'Delivered') {
                            $statusClass = 'delivered';
                        } elseif ($order['status'] == 'Cancelled' || strpos($order['status'], 'Failed') !== false) {
                            $statusClass = 'cancelled';
                        }
                        ?>
                        <span class="status-badge <?php echo $statusClass; ?>"><?php echo $order['status']; ?></span>
                    </div>
                    
                    <!-- Status Message -->
                    <div class="bg-slate-50 rounded-xl p-3 mb-3">
                        <?php 
                        $statusMessages = [
                            'Pending Payment' => '⏳ Payment verification pending. Please wait for admin approval.',
                            'Processing' => '📦 Your parcel is being packed and will be dispatched soon.',
                            'Dispatched' => '🚚 Your order has been dispatched and is on the way!',
                            'Delivered' => '✅ Order delivered successfully! Thank you for shopping with us.',
                            'Payment Failed' => '❌ Payment verification failed. Please try again.',
                            'Cancelled' => '❌ Order cancelled.'
                        ];
                        $currentStatus = $order['status'];
                        $statusKey = $currentStatus;
                        if (strpos($currentStatus, 'Pending') !== false) $statusKey = 'Pending Payment';
                        ?>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-circle text-blue-500 text-[8px]"></i>
                            <span class="font-bold text-slate-700 text-sm"><?php echo $statusMessages[$statusKey] ?? $currentStatus; ?></span>
                        </div>
                        <?php if (isset($order['status_updates']) && is_array($order['status_updates'])): ?>
                        <div class="mt-2 space-y-1">
                            <?php foreach ($order['status_updates'] as $update): ?>
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                <i class="fa-solid fa-clock text-slate-400"></i>
                                <span><?php echo $update['message']; ?></span>
                                <span class="text-slate-400">(<?php echo date('d M H:i', strtotime($update['time'])); ?>)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex gap-3 mb-2">
                        <div class="flex-1">
                            <?php if (!empty($order['items'])): ?>
                            <p class="font-bold text-slate-700 text-sm"><?php echo $order['items'][0]['name']; ?></p>
                            <?php if (count($order['items']) > 1): ?>
                            <p class="text-xs text-slate-400">+<?php echo count($order['items']) - 1; ?> more</p>
                            <?php endif; ?>
                            <?php endif; ?>
                            <p class="text-blue-600 font-black text-base mt-1"><?php echo formatPrice($order['total']); ?></p>
                        </div>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-xs text-slate-400">Delivery Address: <?php echo $order['customer_address'] ?? 'Not specified'; ?></p>
                        <p class="text-xs text-slate-400">City: <?php echo $order['customer_city'] ?? ''; ?>, Area: <?php echo $order['customer_area'] ?? ''; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- PAGE: PROFILE -->
    <div id="page-profile" class="page fade-in">
        <?php if ($currentUser): ?>
        <div class="pb-8">
            <div class="section-header-main px-4 pt-6 pb-16 text-white text-center">
                <div class="w-20 h-20 bg-white bg-opacity-20 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-user text-3xl text-white"></i>
                </div>
                <h2 class="text-xl font-black"><?php echo $currentUser['name']; ?></h2>
                <p class="text-blue-200 text-sm mt-0.5"><?php echo $currentUser['email']; ?></p>
                <div class="flex flex-wrap items-center justify-center gap-3 mt-2">
                    <p class="text-sm font-bold coin-badge bg-amber-400/30 text-white"><i class="fa-solid fa-coins mr-1"></i><?php echo $currentUser['coins'] ?? 0; ?> <?php echo COIN_NAME; ?></p>
                    <button onclick="showPage('buy-coins')" class="buy-coins-btn text-xs"><i class="fa-solid fa-plus mr-1"></i>Buy Coins</button>
                </div>
            </div>

            <div class="mx-4 -mt-8 bg-white rounded-2xl shadow-lg p-4 grid grid-cols-3 gap-2 mb-4">
                <div class="text-center">
                    <p class="font-black text-blue-600 text-xl"><?php echo count($orders); ?></p>
                    <p class="text-slate-500 text-xs font-semibold">Orders</p>
                </div>
                <div class="text-center border-x border-slate-100">
                    <p class="font-black text-blue-600 text-xl"><?php echo formatPrice(array_sum(array_column($orders, 'total'))); ?></p>
                    <p class="text-slate-500 text-xs font-semibold">Spent</p>
                </div>
                <div class="text-center">
                    <p class="font-black text-blue-600 text-xl"><?php echo count(array_filter($orders, function($o) { return $o['status'] === 'Delivered'; })); ?></p>
                    <p class="text-slate-500 text-xs font-semibold">Delivered</p>
                </div>
            </div>

            <div class="mx-4 bg-white rounded-2xl shadow-md overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-slate-100">
                    <p class="text-sm font-bold text-slate-700"><i class="fa-solid fa-envelope text-blue-500 mr-2"></i><?php echo $currentUser['email']; ?></p>
                </div>
                <div class="px-4 py-3 border-b border-slate-100">
                    <p class="text-sm font-bold text-slate-700"><i class="fa-solid fa-phone text-blue-500 mr-2"></i><?php echo $currentUser['phone'] ?? 'Not set'; ?></p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-sm font-bold text-slate-700"><i class="fa-solid fa-location-dot text-blue-500 mr-2"></i><?php echo $currentUser['address'] ?? 'Not set'; ?></p>
                </div>
            </div>

            <div class="mx-4">
                <a href="logout.php" onclick="return confirm('Are you sure you want to logout?')" class="w-full bg-red-50 text-red-500 py-3 rounded-xl font-black text-sm flex items-center justify-center gap-2 hover:bg-red-100 transition border-2 border-red-100">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- PAGE: LOGIN -->
    <div id="page-login" class="page fade-in">
        <div class="min-h-screen bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 flex flex-col items-center justify-center px-6 py-12">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-white bg-opacity-20 rounded-3xl flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid <?php echo $siteLogo; ?> text-white text-3xl"></i>
                </div>
                <h1 class="text-3xl font-black text-white"><?php echo $siteName; ?></h1>
                <p class="text-blue-200 text-sm mt-1">Pakistan's favorite online shopping store</p>
                <p class="text-blue-300 text-xs mt-1">🇵🇰 Prices in Pakistani Rupees (PKR)</p>
            </div>

            <div class="bg-white rounded-3xl shadow-2xl p-6 w-full max-w-sm">
                <div class="flex gap-1 bg-slate-100 rounded-xl p-1 mb-6">
                    <button id="loginTabBtn" onclick="switchAuthTab('login')" class="flex-1 py-2 rounded-lg text-sm font-bold text-blue-600 bg-white shadow transition">Login</button>
                    <button id="signupTabBtn" onclick="switchAuthTab('signup')" class="flex-1 py-2 rounded-lg text-sm font-bold text-slate-400 transition">Sign Up</button>
                </div>

                <form action="login.php" method="POST" id="loginForm">
                    <input type="hidden" name="action" value="login">
                    <div class="space-y-3">
                        <div class="relative">
                            <i class="fa-solid fa-envelope absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="email" name="email" placeholder="Email address" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                        <div class="relative">
                            <i class="fa-solid fa-lock absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="password" name="password" placeholder="Password" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                    </div>
                    <?php if (isset($_GET['error'])): ?>
                    <p class="text-red-500 text-xs font-semibold mt-2"><?php echo $_GET['error'] == 'blocked' ? 'Your account is blocked.' : 'Invalid credentials.'; ?></p>
                    <?php endif; ?>
                    <button type="submit" class="w-full mt-4 bg-blue-600 text-white py-3 rounded-xl font-black text-sm hover:bg-blue-700 transition">Login to <?php echo $siteName; ?></button>
                </form>

                <form action="login.php" method="POST" id="signupForm" class="hidden">
                    <input type="hidden" name="action" value="signup">
                    <div class="space-y-3">
                        <div class="relative">
                            <i class="fa-solid fa-user absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="text" name="name" placeholder="Full Name" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                        <div class="relative">
                            <i class="fa-solid fa-phone absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="tel" name="phone" placeholder="Phone Number" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                        <div class="relative">
                            <i class="fa-solid fa-envelope absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="email" name="email" placeholder="Email address" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                        <div class="relative">
                            <i class="fa-solid fa-lock absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="password" name="password" placeholder="Password (min 6 chars)" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                        <div class="relative">
                            <i class="fa-solid fa-location-dot absolute left-3 top-3.5 text-slate-400 text-sm"></i>
                            <input type="text" name="address" placeholder="Delivery Address" class="w-full pl-9 pr-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" required />
                        </div>
                    </div>
                    <button type="submit" class="w-full mt-4 bg-blue-600 text-white py-3 rounded-xl font-black text-sm hover:bg-blue-700 transition">Create Account</button>
                </form>
            </div>
        </div>
    </div>

    <!-- PAGE: SEARCH RESULTS -->
    <div id="page-search" class="page fade-in">
        <div class="px-4 pt-4">
            <h2 class="text-base font-black text-slate-800 mb-4">Search Results</h2>
            <div class="grid grid-cols-2 gap-3" id="searchGrid"></div>
            <div id="searchEmpty" class="hidden text-center py-12">
                <i class="fa-solid fa-magnifying-glass text-4xl text-slate-200"></i>
                <p class="text-slate-400 font-semibold mt-3">No results found</p>
            </div>
        </div>
    </div>

    <!-- PAGE: BUY COINS -->
    <div id="page-buy-coins" class="page fade-in">
        <div class="px-4 pt-4">
            <h2 class="text-lg font-black text-slate-800 mb-4"><i class="fa-solid fa-coins text-amber-500 mr-2"></i>Buy Coins</h2>
            
            <div class="bg-white rounded-2xl shadow-md p-4 mb-4">
                <div class="text-center py-4">
                    <div class="w-20 h-20 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fa-solid fa-coins text-3xl text-amber-500"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800">Your Coins</h3>
                    <p class="text-3xl font-black text-amber-500 mt-2"><?php echo $userCoins; ?> <?php echo COIN_NAME; ?></p>
                    <p class="text-sm text-slate-500 mt-1">1 <?php echo COIN_NAME; ?> = <?php echo formatPrice($coinValue); ?></p>
                    <p class="text-xs text-amber-600 mt-2">⏳ Coins are added after admin verification</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-md p-4">
                <h3 class="font-bold text-slate-700 mb-3">Request Coins</h3>
                <p class="text-sm text-slate-500 mb-4">Send payment and upload proof. Admin will verify and approve your request.</p>
                
                <form id="coinRequestForm" enctype="multipart/form-data" onsubmit="return false;">
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1">Number of Coins *</label>
                            <div class="grid grid-cols-3 gap-2 mb-2">
                                <button type="button" onclick="setCoinAmount(10)" class="coin-amount-btn">10</button>
                                <button type="button" onclick="setCoinAmount(25)" class="coin-amount-btn">25</button>
                                <button type="button" onclick="setCoinAmount(50)" class="coin-amount-btn">50</button>
                                <button type="button" onclick="setCoinAmount(100)" class="coin-amount-btn">100</button>
                                <button type="button" onclick="setCoinAmount(250)" class="coin-amount-btn">250</button>
                                <button type="button" onclick="setCoinAmount(500)" class="coin-amount-btn">500</button>
                            </div>
                            <input type="number" id="cr_coins" class="w-full px-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" placeholder="Enter coins count" min="1" required />
                            <p class="text-xs text-slate-400 mt-1">Amount: <span id="coinAmountDisplay">Rs. 0</span></p>
                        </div>
                        
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1">Payment Method *</label>
                            <select id="cr_payment_method" class="w-full px-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" onchange="showCoinPaymentDetails(this.value)">
                                <option value="JazzCash">JazzCash</option>
                                <option value="EasyPaisa">EasyPaisa</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>
                        
                        <!-- Coin Payment Details -->
                        <div id="coinPaymentDetails" class="hidden bg-yellow-50 rounded-xl p-4 border-2 border-yellow-200">
                            <div id="coinPaymentInfo"></div>
                        </div>
                        
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1">Transaction ID *</label>
                            <input type="text" id="cr_transaction_id" class="w-full px-4 py-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-700 border-2 border-transparent focus:border-blue-400" placeholder="Enter transaction ID" required />
                        </div>
                        
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1">Payment Screenshot</label>
                            <div class="file-upload-wrapper w-full">
                                <div class="bg-slate-100 rounded-xl p-4 text-center cursor-pointer hover:bg-slate-200 transition border-2 border-dashed border-slate-300" onclick="document.getElementById('cr_image').click()">
                                    <i class="fa-solid fa-cloud-arrow-up text-2xl text-slate-400 mb-2"></i>
                                    <p class="text-sm text-slate-600">Click to upload payment screenshot</p>
                                    <p class="text-xs text-slate-400">PNG, JPG, JPEG (Max 5MB)</p>
                                    <input type="file" id="cr_image" name="payment_image" accept="image/*" onchange="previewCoinImage(event)" style="display:none;" />
                                </div>
                                <div id="coinImagePreview" class="image-preview mt-2" style="display:none;">
                                    <img id="coinPreviewImg" src="#" alt="Payment Screenshot" style="max-width:200px; border-radius:12px; border:2px solid #e2e8f0;" />
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" onclick="submitCoinRequest()" class="w-full bg-amber-500 text-white py-3 rounded-xl font-black text-sm hover:bg-amber-600 transition">
                            <i class="fa-solid fa-paper-plane mr-2"></i> Submit Coin Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<!-- BOTTOM NAV -->
<nav class="bottom-nav flex justify-around items-center py-2 px-2">
    <button class="bottom-nav-item flex flex-col items-center gap-0.5 px-3 py-1 active-nav" onclick="showPage('home')">
        <i class="fa-solid fa-house text-lg"></i>
        <span class="text-xs font-bold">Home</span>
    </button>
    <button class="bottom-nav-item flex flex-col items-center gap-0.5 px-3 py-1" onclick="showPage('products')">
        <i class="fa-solid fa-store text-lg"></i>
        <span class="text-xs font-bold">Products</span>
    </button>
    <button class="bottom-nav-item flex flex-col items-center gap-0.5 px-3 py-1 relative" onclick="showPage('cart')">
        <i class="fa-solid fa-bag-shopping text-lg"></i>
        <span class="text-xs font-bold">Cart</span>
    </button>
    <button class="bottom-nav-item flex flex-col items-center gap-0.5 px-3 py-1" onclick="showPage('orders')">
        <i class="fa-solid fa-box text-lg"></i>
        <span class="text-xs font-bold">Orders</span>
    </button>
    <button class="bottom-nav-item flex flex-col items-center gap-0.5 px-3 py-1" onclick="showPage('profile')">
        <i class="fa-solid fa-user text-lg"></i>
        <span class="text-xs font-bold">Profile</span>
    </button>
</nav>

<script>
// Product data from PHP
const products = <?php echo json_encode($products); ?>;
const categories = <?php echo json_encode($categories); ?>;
const settings = <?php echo json_encode($settings); ?>;
let currentPage = 'home';
let cartItems = <?php echo json_encode($cartItems); ?>;
let cartTotal = <?php echo $cartTotal; ?>;
const coinValue = <?php echo $coinValue; ?>;
const userCoins = <?php echo $userCoins; ?>;

// ===== TOAST =====
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.style.display = 'none', 3000);
}

// ===== PAGE SWITCHING =====
function showPage(name) {
    <?php if (!isLoggedIn()): ?>
    if (name !== 'login') { showPage('login'); return; }
    <?php endif; ?>
    
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    const pg = document.getElementById('page-' + name);
    if (pg) pg.classList.add('active');
    currentPage = name;
    
    document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.remove('active-nav'));
    const navMap = { home: 0, products: 1, cart: 2, orders: 3, profile: 4 };
    const idx = navMap[name];
    if (idx !== undefined) {
        const items = document.querySelectorAll('.bottom-nav-item');
        if (items[idx]) items[idx].classList.add('active-nav');
    }
    window.scrollTo(0, 0);
}

// ===== AUTH TAB =====
function switchAuthTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('loginForm').style.display = isLogin ? 'block' : 'none';
    document.getElementById('signupForm').style.display = isLogin ? 'none' : 'block';
    document.getElementById('loginTabBtn').className = `flex-1 py-2 rounded-lg text-sm font-bold transition ${isLogin ? 'text-blue-600 bg-white shadow' : 'text-slate-400 bg-transparent'}`;
    document.getElementById('signupTabBtn').className = `flex-1 py-2 rounded-lg text-sm font-bold transition ${!isLogin ? 'text-blue-600 bg-white shadow' : 'text-slate-400 bg-transparent'}`;
}

// ===== CART FUNCTIONS =====
function addToCart(productId) {
    <?php if (!isLoggedIn()): ?>
    showToast('Please login first');
    showPage('login');
    return;
    <?php endif; ?>
    
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add_to_cart&product_id=' + productId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('🛒 Added to cart');
            location.reload();
        }
    });
}

function removeFromCart(productId) {
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove_from_cart&product_id=' + productId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('🗑️ Removed from cart');
            location.reload();
        }
    });
}

function updateCartQty(productId, delta) {
    const qtyEl = document.querySelector(`#cartItems .flex .font-bold.text-sm.w-5.text-center`);
    if (!qtyEl) return;
    let qty = parseInt(qtyEl.textContent) + delta;
    if (qty < 1) {
        removeFromCart(productId);
        return;
    }
    
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_cart_qty&product_id=${productId}&quantity=${qty}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.reload();
    });
}

// ===== BUY WITH COINS (Redirects to checkout) =====
function buyWithCoins(productId) {
    <?php if (!isLoggedIn()): ?>
    showToast('Please login first');
    showPage('login');
    return;
    <?php endif; ?>
    
    const product = products.find(p => p.id === productId);
    if (!product) return;
    
    const neededCoins = Math.ceil(product.price / coinValue);
    if (userCoins < neededCoins) {
        showToast('❌ Insufficient coins! You need ' + neededCoins + ' coins. Buy more coins from the Buy Coins section.');
        showPage('buy-coins');
        return;
    }
    
    // Directly go to checkout with coin payment
    if (confirm(`Buy "${product.name}" with ${neededCoins} <?php echo COIN_NAME; ?> coins?`)) {
        window.location.href = 'checkout.php?product_id=' + productId + '&pay_with_coins=1';
    }
}

// ===== PAY CART WITH COINS =====
function payCartWithCoins() {
    <?php if (!isLoggedIn()): ?>
    showToast('Please login first');
    showPage('login');
    return;
    <?php endif; ?>
    
    const totalCoinsNeeded = Math.ceil(cartTotal / coinValue);
    if (userCoins < totalCoinsNeeded) {
        showToast('❌ Insufficient coins! You need ' + totalCoinsNeeded + ' coins.');
        showPage('buy-coins');
        return;
    }
    
    if (!confirm(`Pay entire cart with ${totalCoinsNeeded} <?php echo COIN_NAME; ?> coins?`)) return;
    
    window.location.href = 'checkout.php?pay_with_coins=1';
}

// ===== BUY COINS =====
function setCoinAmount(amount) {
    document.getElementById('cr_coins').value = amount;
    updateCoinAmountDisplay();
    
    const method = document.getElementById('cr_payment_method').value;
    showCoinPaymentDetails(method);
}

function updateCoinAmountDisplay() {
    const coins = parseInt(document.getElementById('cr_coins').value) || 0;
    const amount = coins * coinValue;
    document.getElementById('coinAmountDisplay').textContent = 'Rs. ' + amount.toLocaleString('en-IN');
    
    const method = document.getElementById('cr_payment_method').value;
    showCoinPaymentDetails(method);
}

function showCoinPaymentDetails(method) {
    const detailsDiv = document.getElementById('coinPaymentDetails');
    const infoDiv = document.getElementById('coinPaymentInfo');
    const coins = parseInt(document.getElementById('cr_coins').value) || 0;
    const amount = coins * coinValue;
    
    let html = '';
    let show = false;
    
    if (method === 'JazzCash') {
        html = `
            <p class="text-sm font-bold text-slate-700"><i class="fa-solid fa-mobile-screen-button text-red-500 mr-2"></i>JazzCash Payment</p>
            <p class="text-sm text-slate-600 mt-1">Send payment to the following JazzCash number:</p>
            <div class="bg-white rounded-lg p-3 mt-2 text-center border-2 border-dashed border-blue-400">
                <p class="text-lg font-black text-blue-600">${settings.jazzcash_number || '0300-1234567'}</p>
                <p class="text-xs text-slate-500 mt-1">Amount: ${formatPrice(amount)}</p>
            </div>
            <p class="text-xs text-slate-500 mt-2">After sending payment, provide transaction ID and payment screenshot for verification.</p>
        `;
        show = true;
    } else if (method === 'EasyPaisa') {
        html = `
            <p class="text-sm font-bold text-slate-700"><i class="fa-solid fa-mobile-screen-button text-orange-500 mr-2"></i>EasyPaisa Payment</p>
            <p class="text-sm text-slate-600 mt-1">Send payment to the following EasyPaisa number:</p>
            <div class="bg-white rounded-lg p-3 mt-2 text-center border-2 border-dashed border-blue-400">
                <p class="text-lg font-black text-blue-600">${settings.easypaisa_number || '0311-1234567'}</p>
                <p class="text-xs text-slate-500 mt-1">Amount: ${formatPrice(amount)}</p>
            </div>
            <p class="text-xs text-slate-500 mt-2">After sending payment, provide transaction ID and payment screenshot for verification.</p>
        `;
        show = true;
    } else if (method === 'Bank Transfer') {
        html = `
            <p class="text-sm font-bold text-slate-700"><i class="fa-solid fa-building-columns text-purple-600 mr-2"></i>Bank Transfer</p>
            <p class="text-sm text-slate-600 mt-1">Transfer payment to the following bank account:</p>
            <div class="bg-white rounded-lg p-3 mt-2 text-center border-2 border-dashed border-blue-400">
                <p class="text-sm font-bold text-slate-700">${settings.bank_account || 'Bank: HBL, Account: 123456789'}</p>
                <p class="text-xs text-slate-500 mt-1">Amount: ${formatPrice(amount)}</p>
            </div>
            <p class="text-xs text-slate-500 mt-2">After sending payment, provide transaction ID and payment screenshot for verification.</p>
        `;
        show = true;
    }
    
    if (show && coins > 0) {
        detailsDiv.classList.remove('hidden');
        infoDiv.innerHTML = html;
    } else {
        detailsDiv.classList.add('hidden');
    }
}

function formatPrice(amount) {
    return 'Rs. ' + Number(amount).toLocaleString('en-IN');
}

function previewCoinImage(event) {
    const preview = document.getElementById('coinImagePreview');
    const img = document.getElementById('coinPreviewImg');
    const file = event.target.files[0];
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
}

function submitCoinRequest() {
    <?php if (!isLoggedIn()): ?>
    showToast('Please login first');
    showPage('login');
    return;
    <?php endif; ?>
    
    const coins = parseInt(document.getElementById('cr_coins').value);
    const paymentMethod = document.getElementById('cr_payment_method').value;
    const transactionId = document.getElementById('cr_transaction_id').value.trim();
    
    if (!coins || coins <= 0) {
        showToast('Please enter valid number of coins');
        return;
    }
    
    if (!transactionId) {
        showToast('Please enter transaction ID');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'request_coins');
    formData.append('coins', coins);
    formData.append('payment_method', paymentMethod);
    formData.append('transaction_id', transactionId);
    
    const fileInput = document.getElementById('cr_image');
    if (fileInput.files && fileInput.files[0]) {
        formData.append('payment_image', fileInput.files[0]);
    }
    
    if (!confirm(`Request ${coins} <?php echo COIN_NAME; ?> coins? Wait for admin approval.`)) return;
    
    fetch('coin_request.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + data.message);
            document.getElementById('cr_coins').value = '';
            document.getElementById('cr_transaction_id').value = '';
            document.getElementById('coinImagePreview').style.display = 'none';
            document.getElementById('coinPaymentDetails').classList.add('hidden');
            updateCoinAmountDisplay();
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast('❌ ' + data.message);
        }
    });
}

// ===== PRODUCT FILTER =====
function renderProducts(category) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.filter-btn').forEach(b => {
        if (b.textContent.trim() === category) {
            b.classList.add('active');
        }
    });
    
    const filtered = category === 'All' ? products : products.filter(p => p.category === category);
    const grid = document.getElementById('productGrid');
    const noRes = document.getElementById('noResults');
    
    if (filtered.length === 0) {
        grid.innerHTML = '';
        noRes.classList.remove('hidden');
        return;
    }
    noRes.classList.add('hidden');
    grid.innerHTML = filtered.map(p => `
        <div class="bg-white rounded-2xl shadow-md overflow-hidden cursor-pointer hover:shadow-lg transition-shadow" onclick="window.location.href='product.php?id=${p.id}'">
            <div class="relative">
                <img src="${p.images && p.images.length > 0 ? p.images[0] : (p.image || 'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=400&q=80')}" alt="${p.name}" class="product-img" loading="lazy" />
                ${p.stock < 10 ? `<span class="absolute top-2 left-2 bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">Only ${p.stock} left</span>` : ''}
                <span class="absolute top-2 right-2 bg-white bg-opacity-90 text-xs font-bold px-2 py-0.5 rounded-full text-slate-600 shadow">${p.category}</span>
            </div>
            <div class="p-3">
                <p class="text-slate-800 font-bold text-sm leading-tight line-clamp-2 mb-1">${p.name}</p>
                <div class="flex items-center justify-between">
                    <span class="text-blue-600 font-black text-lg">Rs. ${Number(p.price).toLocaleString('en-IN')}</span>
                    <div class="flex items-center gap-1">
                        ${userCoins > 0 ? `<button onclick="event.stopPropagation(); buyWithCoins(${p.id})" class="coin-buy-btn" title="Buy with Coins"><i class="fa-solid fa-coins"></i></button>` : ''}
                        <button onclick="event.stopPropagation(); addToCart(${p.id})" class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center hover:scale-110 transition-transform shadow"><i class="fa-solid fa-plus text-xs"></i></button>
                    </div>
                </div>
                ${userCoins > 0 ? `<div class="mt-1"><span class="text-[10px] text-amber-600 font-semibold"><i class="fa-solid fa-coins mr-1"></i>${Math.ceil(p.price / coinValue)} <?php echo COIN_NAME; ?></span></div>` : ''}
            </div>
        </div>
    `).join('');
}

function filterByCategory(cat) {
    renderProducts(cat);
    showPage('products');
}

// ===== SEARCH =====
function handleSearch(q) {
    if (!q) { if (currentPage === 'search') showPage('home'); return; }
    const results = products.filter(p => 
        p.name.toLowerCase().includes(q.toLowerCase()) ||
        p.category.toLowerCase().includes(q.toLowerCase())
    );
    const grid = document.getElementById('searchGrid');
    const empty = document.getElementById('searchEmpty');
    document.querySelector('#page-search h2').textContent = `Results for "${q}"`;
    if (results.length === 0) {
        grid.innerHTML = '';
        empty.classList.remove('hidden');
    } else {
        empty.classList.add('hidden');
        grid.innerHTML = results.map(p => `
            <div class="bg-white rounded-2xl shadow-md overflow-hidden cursor-pointer hover:shadow-lg transition-shadow" onclick="window.location.href='product.php?id=${p.id}'">
                <div class="relative">
                    <img src="${p.images && p.images.length > 0 ? p.images[0] : (p.image || 'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=400&q=80')}" alt="${p.name}" class="product-img" loading="lazy" />
                    <span class="absolute top-2 right-2 bg-white bg-opacity-90 text-xs font-bold px-2 py-0.5 rounded-full text-slate-600 shadow">${p.category}</span>
                </div>
                <div class="p-3">
                    <p class="text-slate-800 font-bold text-sm leading-tight line-clamp-2 mb-1">${p.name}</p>
                    <div class="flex items-center justify-between">
                        <span class="text-blue-600 font-black text-lg">Rs. ${Number(p.price).toLocaleString('en-IN')}</span>
                        <button onclick="event.stopPropagation(); addToCart(${p.id})" class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center hover:scale-110 transition-transform shadow"><i class="fa-solid fa-plus text-xs"></i></button>
                    </div>
                </div>
            </div>
        `).join('');
    }
    if (currentPage !== 'search') {
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        document.getElementById('page-search').classList.add('active');
        currentPage = 'search';
    }
}

// ===== BANNER SLIDER =====
let currentBanner = 0;
const totalBanners = <?php echo count($activeBanners); ?>;

function changeBanner(direction) {
    if (totalBanners <= 1) return;
    currentBanner = (currentBanner + direction + totalBanners) % totalBanners;
    updateBanner();
}

function goToBanner(index) {
    currentBanner = index;
    updateBanner();
}

function updateBanner() {
    const container = document.getElementById('bannerSlides');
    if (container) {
        container.style.transform = `translateX(-${currentBanner * 100}%)`;
    }
    document.querySelectorAll('#bannerDots .dot').forEach((dot, i) => {
        dot.classList.toggle('active', i === currentBanner);
    });
}

// Auto slide banners
let bannerInterval = setInterval(() => changeBanner(1), 5000);

// Pause on hover
const bannerSlider = document.querySelector('.banner-slider');
if (bannerSlider) {
    bannerSlider.addEventListener('mouseenter', () => {
        clearInterval(bannerInterval);
    });
    bannerSlider.addEventListener('mouseleave', () => {
        bannerInterval = setInterval(() => changeBanner(1), 5000);
    });
}

// ===== INIT =====
<?php if (!isLoggedIn()): ?>
showPage('login');
<?php else: ?>
showPage('home');
<?php endif; ?>
</script>
</body>
</html>