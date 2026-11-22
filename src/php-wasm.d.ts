declare module '@php-wasm/node-8-3' {
  import type { PHPLoaderModule } from '@php-wasm/universal';
  export function getPHPLoaderModule(): Promise<PHPLoaderModule>;
  export function jspi(): Promise<boolean>;
}
