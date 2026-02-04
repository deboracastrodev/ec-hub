<?php

declare(strict_types=1);

namespace Database\Seeders;

use Faker\Factory as FakerFactory;
use PDO;

/**
 * Product Seeder
 *
 * Generates 50-100 realistic fake products using Faker library and PDO.
 */
class ProductSeeder
{
    private PDO $pdo;

    private array $categories = [
        'Eletrônicos',
        'Livros',
        'Roupas',
        'Casa',
        'Esportes',
        'Beleza',
        'Tecnologia',
    ];

    private array $categoryProducts = [
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

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Seed database with products and return summary.
     */
    public function run(int $minProducts = 50, int $maxProducts = 100): array
    {
        $this->pdo->exec('DELETE FROM products');

        $productCount = random_int($minProducts, $maxProducts);
        $faker = FakerFactory::create('pt_BR');

        $stmt = $this->pdo->prepare("
            INSERT INTO products (name, description, price, category, image_url)
            VALUES (:name, :description, :price, :category, :image_url)
        ");

        for ($i = 0; $i < $productCount; $i++) {
            $category = $faker->randomElement($this->categories);
            $productName = $this->fakerNameForCategory($faker, $category);
            $price = $faker->randomFloat(2, 10, 500);

            $stmt->execute([
                'name' => $productName,
                'description' => $faker->paragraph(3),
                'price' => $price,
                'category' => $category,
                'image_url' => $this->generateImageUrl($category),
            ]);
        }

        return $this->summaries();
    }

    private function summaries(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $categoryStmt = $this->pdo->query('SELECT category, COUNT(*) as count FROM products GROUP BY category ORDER BY count DESC');

        return [
            'total' => $total,
            'categories' => $categoryStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function fakerNameForCategory($faker, string $category): string
    {
        $categoryProducts = $this->categoryProducts[$category] ?? [];
        $customNames = array_merge($categoryProducts, [
            $faker->sentence(3),
            $faker->sentence(2),
        ]);

        return $faker->randomElement($customNames);
    }

    private function generateImageUrl(string $category): string
    {
        return sprintf(
            'https://placehold.co/400x400/FF6B6B/ffffff?text=%s',
            urlencode(substr($category, 0, 15))
        );
    }
}
