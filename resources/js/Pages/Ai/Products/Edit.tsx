import { ProductForm } from '@/Components/ai/products/ProductForm';
import { NoPermission } from '@/Components/ui/NoPermission';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { ProductsEditProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

/** Editar producto (C-ProductForm). No destroy here: a product leaves service with the state "Retirado". */
export default function ProductsEdit(props: PageProps<ProductsEditProps>) {
    const { product } = props;
    return (
        <GestorLayout
            shared={props}
            title={`Editar ${product.name} · Productos`}
            tab="products"
            mode="it"
            breadcrumb={[
                { label: 'Productos', href: route('ai.products.index') },
                { label: product.name, href: route('ai.products.show', { product: product.slug }) },
                { label: 'Editar' },
            ]}
        >
            {can(props.auth.user, 'ai.manage') ? (
                <ProductForm {...props} mode="edit" />
            ) : (
                <NoPermission description="Crear y editar productos es del equipo de TI. Si necesitas un cambio en la ficha, pídeselo a Jhon Castro." />
            )}
        </GestorLayout>
    );
}
