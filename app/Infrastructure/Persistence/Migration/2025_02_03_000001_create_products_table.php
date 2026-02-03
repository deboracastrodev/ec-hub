<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migration;

use Hyperf\Database\Migration\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateProductsTable extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->string('category', 100);
            $table->string('image_url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('name', 'idx_products_name');
            $table->index('category', 'idx_products_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
}
