const cartKey = 'canteenpro-cart';
const getCart = () => JSON.parse(localStorage.getItem(cartKey) || '[]');
const setCart = cart => { localStorage.setItem(cartKey, JSON.stringify(cart)); updateCart(); };
const updateCart = () => {
  const count = getCart().length;
  document.querySelectorAll('[data-cart-count]').forEach(el => el.textContent = count);
  document.querySelectorAll('[data-cart-text]').forEach(el => el.textContent = count ? ` ${count} item${count === 1 ? '' : 's'} ready to review.` : ' Your cart is empty.');
};

document.querySelectorAll('[data-demo-login]').forEach(form => form.addEventListener('submit', event => {
  event.preventDefault();
  const role = form.dataset.role;
  window.location.href = role === 'staff' ? 'staff.html' : 'customer.html';
}));
document.querySelectorAll('[data-register]').forEach(form => form.addEventListener('submit', event => {
  event.preventDefault();
  window.location.href = 'customer.html';
}));
document.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
  const cart = getCart();
  cart.push(button.dataset.add);
  setCart(cart);
  button.textContent = 'Added';
  setTimeout(() => { button.textContent = 'Add to order'; }, 1000);
}));
document.querySelectorAll('[data-filter]').forEach(button => button.addEventListener('click', () => {
  document.querySelectorAll('[data-filter]').forEach(item => item.classList.remove('active'));
  button.classList.add('active');
  document.querySelectorAll('[data-category]').forEach(item => { item.hidden = button.dataset.filter !== 'all' && item.dataset.category !== button.dataset.filter; });
}));
document.querySelectorAll('[data-reserve]').forEach(button => button.addEventListener('click', () => { button.textContent = 'Reservation requested'; button.disabled = true; }));
document.querySelectorAll('[data-checkout]').forEach(button => button.addEventListener('click', () => { alert(getCart().length ? `${getCart().length} item(s) in your demo cart.` : 'Your cart is empty.'); }));
document.querySelectorAll('[data-status], [data-complete]').forEach(button => button.addEventListener('click', () => { button.textContent = button.dataset.complete !== undefined ? 'Collected' : 'Updated'; button.disabled = true; }));
updateCart();
