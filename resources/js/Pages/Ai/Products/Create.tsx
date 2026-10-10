import { ProductForm } from '@/Components/ai/products/ProductForm';
import { NoPermission } from '@/Components/ui/NoPermission';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { ProductsCreateProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

/** Nuevo producto: the C-ProductForm opened empty; the slug follows the name until edited. */
export default function ProductsCreate(props: PageProps<ProductsCreateProps>) {
    return (
        <GestorLayout
            shared={props}
            title="Nuevo producto · Productos"
            tab="products"
            mode="it"
            breadcrumb={[{ label: 'Productos', href: route('ai.products.index') }, { label: 'Nuevo producto' }]}
        >
            {can(props.auth.user, 'ai.manage') ? (
                <ProductForm {...props} mode="create" />
            ) : (
                <NoPermission description="Crear y editar productos es del equipo de TI. Si necesitas un cambio en la ficha, pídeselo a Jhon Castro." />
            )}
        </GestorLayout>
    );
}
