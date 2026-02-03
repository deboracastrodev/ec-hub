<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Database configuration from Docker environment variables
$config = [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'ec_hub',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

// Create PDO connection
$dsn = sprintf(
    '%s:host=%s;port=%d;dbname=%s;charset=%s',
    $config['driver'],
    $config['host'],
    $config['port'],
    $config['database'],
    'utf8mb4'
);

try {
    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "✅ Conectado ao banco de dados\n";

    // Clean existing products (idempotência)
    $pdo->exec("DELETE FROM products");
    echo "🧹 Produtos existentes removidos\n";

    // Categories and products
    $categories = [
        'Eletrônicos' => [
            'Fone Bluetooth Wireless Premium',
            'Smartwatch Fitness Tracker',
            'Tablet 10 polegadas HD',
            'Notebook Gamer i7 16GB',
            'Fone de Ouvido Cancelamento Ruído',
            'Caixa de Som Bluetooth Portátil',
            'Smart TV 55" 4K',
            'Camera Digital 24MP',
            'Drone com Camera 4K',
            'Monitor Gamer 27" 144Hz',
        ],
        'Livros' => [
            'Romance Bestseller Nacional',
            'Livro Técnico PHP Avançado',
            'Ficção Científica Espacial',
            'Didático Matemática Básica',
            'Biografia de Liderança',
            'Livro de Receitas Vegetarianas',
            'Mistério e Suspense',
            'História em Quadrinhos HQ',
            'Autoajuda Produtividade',
            'Livro Infantil Ilustrado',
        ],
        'Roupas' => [
            'Camiseta Algodão Premium',
            'Calça Jeans Slim Fit',
            'Vestido Verão Leve',
            'Tênis Esportivo Confortável',
            'Jaqueta Couro Legítima',
            'Blusa Social Elegante',
            'Shorts Praia Fresco',
            'Saia Midi Casual',
            'Casaco Inverno Aconchegante',
            'Macacão Fashion Moderno',
        ],
        'Casa' => [
            'Decorativo Parede Quadro',
            'Panela Antiaderente Set',
            'Lâmpada LED Mesa',
            'Toalha Banho Premium',
            'Organizador Cozinha',
            'Vaso Cerâmica Decorativo',
            'Almofada Sofá Confortável',
            'Cortina Blackout Janela',
            'Tapete Sala Nobre',
            'Espelho Retangular Banheiro',
        ],
        'Esportes' => [
            'Bola Futebol Oficial',
            'Chuteira Profissional',
            'Raquete Tênis Carbono',
            'Piscina Inflável Família',
            'Halteres Academia Kit',
            'Bicicleta Mountain Bike',
            'Colchonete Yoga Espesso',
            'Esteira Ergométrica Dobrável',
            'Patins Inline Ajustável',
            'Bola Basquete Profissional',
        ],
        'Beleza' => [
            'Kit Maquiagem Completo',
            'Skincare Facial Rotina',
            'Perfume Importado 100ml',
            'Cabelo Shampoo Premium',
            'Escova Dental Elétrica',
            'Creme Anti-Idade',
            'Batom Matte Longa Duração',
            'Pincel Maquiagem Kit',
            'Protetor Solar FPS 50',
            'Óleo Capilar Nutritivo',
        ],
        'Tecnologia' => [
            'Cabo USB Type-C Rápido',
            'Carregador Turbo 30W',
            'Mouse Gamer RGB',
            'Teclado Mecânico Switch',
            'Monitor Ultrawide 34"',
            'Webcam HD 1080p',
            'Hub USB 3.0 7 Portas',
            'Suporte Notebook Ajustável',
            'HD Externo 2TB SSD',
            'Roteador WiFi 6 Dual Band',
        ],
    ];

    $faker = \Faker\Factory::create('pt_BR');
    $productCount = rand(50, 100);

    echo "📦 Gerando {$productCount} produtos...\n";

    $stmt = $pdo->prepare("
        INSERT INTO products (name, description, price, category, image_url)
        VALUES (:name, :description, :price, :category, :image_url)
    ");

    for ($i = 0; $i < $productCount; $i++) {
        $category = array_rand($categories);
        $categoryProducts = $categories[$category];

        // 70% chance to use predefined name, 30% random
        if (rand(1, 100) <= 70 && !empty($categoryProducts)) {
            $productName = $categoryProducts[array_rand($categoryProducts)];
        } else {
            $productName = $faker->sentence(3);
        }

        $price = $faker->randomFloat(2, 10, 500);

        $categorySlug = strtolower(str_replace(' ', '-', $category));
        $imageUrl = sprintf(
            'https://placehold.co/400x400/FF6B6B/ffffff?text=%s',
            urlencode(substr($category, 0, 15))
        );

        $stmt->execute([
            'name' => $productName,
            'description' => $faker->paragraph(3),
            'price' => $price,
            'category' => $category,
            'image_url' => $imageUrl,
        ]);

        if (($i + 1) % 10 === 0) {
            echo "  Criados " . ($i + 1) . " produtos...\n";
        }
    }

    // Count products
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $countStmt->fetch()['total'];

    // Show products by category
    $catStmt = $pdo->query("SELECT category, COUNT(*) as count FROM products GROUP BY category ORDER BY count DESC");
    $categoryCounts = $catStmt->fetchAll();

    echo "\n📊 Resumo:\n";
    echo "  Total de produtos: {$total}\n";
    echo "\n  Por categoria:\n";
    foreach ($categoryCounts as $cat) {
        echo "    - {$cat['category']}: {$cat['count']}\n";
    }

} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✨ Seeder concluído com sucesso!\n";
