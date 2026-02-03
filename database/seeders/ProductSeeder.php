<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Product\Model\Product;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use Hyperf\Database\Seed\Seeder;
use Hyperf\DbConnection\Db;

/**
 * Product Seeder
 *
 * Generates 50-100 realistic fake products using Faker library.
 * Categories: Eletrônicos, Livros, Roupas, Casa, Esportes, Beleza, Tecnologia.
 */
class ProductSeeder extends Seeder
{
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

    public function run(): void
    {
        $repository = new ProductRepository(Db::connection());

        // Limpar produtos existentes (idempotência)
        $this->db->table('products')->delete();

        // Gerar 50-100 produtos
        $productCount = rand(50, 100);
        $faker = \Faker\Factory::create('pt_BR');

        $this->output->writeln("Gerando {$productCount} produtos...");

        for ($i = 0; $i < $productCount; $i++) {
            $category = $faker->randomElement($this->categories);

            // Usar nome específico da categoria ou gerar aleatório
            $categoryProducts = $this->categoryProducts[$category];
            $productNames = array_merge($categoryProducts, [
                $faker->sentence(3),
                $faker->sentence(2),
            ]);
            $productName = $faker->randomElement($productNames);

            // Gerar preço entre R$ 10,00 e R$ 500,00
            $priceCents = $faker->numberBetween(1000, 50000);

            $product = new Product(
                name: $productName,
                description: $faker->paragraph(3),
                price: new Money($priceCents),
                category: $category,
                imageUrl: $this->generateImageUrl($category, $productName)
            );

            $repository->create([
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => $product->getPrice()->getDecimal(),
                'category' => $product->getCategory(),
                'image_url' => $product->getImageUrl(),
            ]);

            if (($i + 1) % 10 === 0) {
                $this->output->writeln("Criados " . ($i + 1) . " produtos...");
            }
        }

        $this->output->writeln("<info>Total de {$productCount} produtos criados com sucesso!</info>");
    }

    private function generateImageUrl(string $category, string $productName): string
    {
        // Usar placehold.co para imagens placeholder mais bonitas
        $categorySlug = strtolower(str_replace(' ', '-', $category));
        return sprintf(
            'https://placehold.co/400x400/FF6B6B/ffffff?text=%s',
            urlencode(substr($category, 0, 15))
        );
    }
}
